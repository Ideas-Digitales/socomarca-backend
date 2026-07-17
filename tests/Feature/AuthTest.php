<?php

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\Security\PasswordGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

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

test('allow user to change its credentials when it doesn\'t have an associated email', function($dbRandomRut, $loginRut) {
    /** @var TestCase $this */

    Mail::fake();

    $user = User::factory()->create([
        'email' => null,
        'password' => null,
        'rut' => $dbRandomRut,
        'is_active' => true,
    ]);
    $user->assignRole('customer');
    $response = postJson(route('auth.password.restore'), [
        'rut' => $loginRut,
    ]);

    $provisionalToken = $response->json('data.provisional_token');
    $provisionalToken = "Bearer {$provisionalToken}";
    $newUserEmail = fake()->email();
    $newPassword = fake()->password();
    $this->instance(
        PasswordGeneratorService::class,
        Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($newPassword) {
            $mock->expects('generate')->andReturn($newPassword);
        })
    );
    // Provisional token must not work for api access, it's just for credentials update
    getJson(route('products.index'), ['Authorization' => $provisionalToken])->assertUnauthorized();
    // Updates credentials using provisional token
    $response = patchJson(route('credentials.update'), [
        'email' => $newUserEmail,
    ], ['Authorization' => $provisionalToken]);
    $response->assertOk();

    // Tests temporary email sent
    Mail::assertQueued(TemporaryPasswordMail::class, function ($mail) use ($newUserEmail) {
        return $mail->hasTo($newUserEmail);
    });

    auth()->forgetUser(); // IMPORTANT! Reset auth state
    $user->refresh();

    // Login with new credentials
    $response = postJson(route('auth.token.store'), [
        'rut' => $user->rut,
        'password' => $newPassword,
    ]);
    $response->assertOk();
    $token = $response->json('token');
    $token = "Bearer {$token}";
    // Access token must work for api access
    getJson(
        route('products.index'),
        ['Authorization' => $token],
    )->assertOk();

    expect($user->email == $newUserEmail)->toBeTrue();
})->with('ValidForLoginRuts');
