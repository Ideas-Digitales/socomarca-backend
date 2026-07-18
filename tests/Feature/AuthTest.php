<?php

use App\DTOs\Password;
use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\Security\PasswordGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('User can perform a successful login using valid credentials', function () {
    // Arrange
    User::factory()->create([
        'rut' => '17260847-7',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    // Act
    $response = postJson(route('auth.token.store'), [
        'rut' => '17260847-7',
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Assert
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
    $response = postJson(route('auth.token.store'), [
        'rut' => $loginRut,
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Assert
    $response->assertStatus(200);
})->with('ValidForLoginRuts');

test('User can\'t perform a successful login when using invalid credentials', function () {
    // Arrange
    User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('password123'),
    ]);

    // Act
    $response = postJson(route('auth.token.store'), [
        'rut' => '11111111-1',
        'password' => 'wrongpassword',
        'device_name' => 'test-device',
    ]);

    // Assert
    $response->assertStatus(401);
});

test('Inactive user can\'t perform a successful login', function () {
    // Arrange
    User::factory()->create([
        'rut' => '22222222-2',
        'password' => Hash::make('password123'),
        'is_active' => false,
    ]);

    // Act
    $response = postJson(route('auth.token.store'), [
        'rut' => '22222222-2',
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    // Assert
    $response->assertStatus(401)
        ->assertJson([
            'message' => "Unauthorized",
        ]);
    assertGuest();
});

test('User can destroy session', function () {
    // Arrange
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('password123'),
    ]);

    Sanctum::actingAs($user, ['api-access']);

    // Act
    $response = deleteJson(route('auth.token.destroy'));

    // Assert
    $response->assertStatus(200);
});

test('Authenticated user can get its own information', function () {
    // Arrange
    $user = User::factory()->create([
        'rut' => '33333333-3'
    ]);
    $user->assignRole('admin');
    Sanctum::actingAs($user, ['api-access']);

    // Act
    $response = getJson('/api/users/' . $user->id);

    // Assert
    $response->assertStatus(200);
});

test('allow user to change its credentials when it doesn\'t have an associated email', function ($dbRandomRut, $loginRut) {
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
    $newPasswordHash = Hash::make($newPassword);
    $passwordDto = new Password($newPassword, $newPasswordHash);
    $this->instance(
        PasswordGeneratorService::class,
        Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($passwordDto) {
            $mock->expects('generate')->andReturn($passwordDto);
        })
    );
    // Provisional token must not work for api access, it's just for credentials update
    getJson(route('products.index'), ['Authorization' => $provisionalToken])->assertForbidden();
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

test('allow user to change its credentials when it has an associated email', function ($dbRandomRut, $loginRut) {
    /** @var TestCase $this */

    Mail::fake();

    $user = User::factory()->create([
        'password' => null,
        'rut' => $dbRandomRut,
        'is_active' => true,
    ]);
    $user->assignRole('customer');
    $user->save();
    $newPassword = fake()->password();
    $newPasswordHash = Hash::make($newPassword);
    $passwordDto = new Password($newPassword, $newPasswordHash);
    $this->instance(
        PasswordGeneratorService::class,
        Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($passwordDto) {
            $mock->expects('generate')->andReturn($passwordDto);
        })
    );
    postJson(route('auth.password.restore'), [
        'rut' => $loginRut,
    ])
        ->assertJsonFragment(['message' => __('auth.password_reset', ['email' => Str::maskEmail($user->email)])]);

    // Tests temporary email sent
    Mail::assertQueued(TemporaryPasswordMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
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
})->with('ValidForLoginRuts');
