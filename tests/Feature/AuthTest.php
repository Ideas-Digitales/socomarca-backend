<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('usuario puede iniciar sesion con credenciales validas', function () {
    // Preparación
    User::factory()->create([
        'rut' => '17260847-7',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    // Acción
    $response = $this->postJson(route('auth.token.store'), [
        'rut' => '17260847-7',
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Aserción
    $response->assertStatus(200);

});


test('User can login using formatted RUT', function ($dbRandomRut, $loginRut) {
    // Case 1:
    // Arrange
    User::factory()->create([
        'rut' => $dbRandomRut,
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    // Act
    $response = $this->postJson(route('auth.token.store'), [
        'rut' => $loginRut,
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Assert
    $response->assertStatus(200);
})->with('ValidForLoginRuts');

test('usuario no puede iniciar sesion con credenciales invalidas', function () {
    // Preparación
    User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('password123'),
    ]);

    // Acción
    $response = $this->postJson(route('auth.token.store'), [
        'rut' => '11111111-1',
        'password' => 'wrongpassword',
        'device_name' => 'test-device',
    ]);

    // Aserción
    $response->assertStatus(401);
});

test('usuario inactivo no puede iniciar sesion', function () {
    // Preparación
    User::factory()->create([
        'rut' => '22222222-2',
        'password' => Hash::make('password123'),
        'is_active' => false,
    ]);

    // Acción
    $response = $this->postJson(route('auth.token.store'), [
        'rut' => '22222222-2',
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Aserción
    $response->assertStatus(401)
        ->assertJson([
            'message' => "Unauthorized",
        ]);
    $this->assertGuest();
});

test('usuario puede cerrar sesion', function () {
    // Preparación
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('password123'),
    ]);

    Sanctum::actingAs($user);

    // Acción
    $response = $this->deleteJson(route('auth.token.destroy'));

    // Aserción
    $response->assertStatus(200);
});

test('usuario autenticado puede obtener su informacion', function () {
    // Preparación
    $user = User::factory()->create([
        'rut' => '33333333-3'
    ]);
    $user->assignRole('admin');
    Sanctum::actingAs($user);

    // Acción
    $response = $this->getJson('/api/users/' . $user->id);

    // Aserción
    $response->assertStatus(200);
});
