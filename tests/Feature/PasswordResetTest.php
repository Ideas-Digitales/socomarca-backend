<?php

use App\DTOs\Password;
use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\Security\PasswordGeneratorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

it('allows requesting a password reset with a valid RUT', function () {
    /** @var \Tests\TestCase $this */
    Mail::fake();
    Event::fake();

    $user = User::factory()->create([
        'rut' => '11111111-1',
        'email' => 'test@example.com',
    ]);

    $newPassword = fake()->password();
    $newPasswordHash = Hash::make($newPassword);
    $passwordDto = new Password($newPassword, $newPasswordHash);
    $this->instance(
        PasswordGeneratorService::class,
        Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($passwordDto) {
            $mock->expects('generate')->andReturn($passwordDto);
        })
    );
    $response = postJson(route('auth.password.restore'), [
        'rut' => '11111111-1',
    ]);

    $maskedEmail =Str::maskEmail($user->email);
    $response->assertStatus(200)
        ->assertJson([
            'message' => __('auth.password_reset', ['email' => $maskedEmail]),
            'data' => [
                'email' => $maskedEmail,
            ]
        ])
        ->assertJsonStructure([
            'message',
            'data' => [
                'email',
            ]
        ]);

    assertDatabaseHas('users', [
        'rut' => $user->rut,
        'password_changed_at' => null,
    ]);

    $updatedUser = User::where('rut', $user->rut)->first();
    expect(Hash::check($passwordDto->password, $updatedUser->password))->toBeTrue();

    Mail::assertQueued(TemporaryPasswordMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

it('rejects a password reset request when the RUT does not exist', function () {
    Mail::fake();

    $response = postJson(route('auth.password.restore'), [
        'rut' => '00000000-0',
    ]);

    $response->assertStatus(403);
});

it('allows an authenticated user to change their password', function () {
    Event::fake();
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('currentpassword'),
        'password_changed_at' => null,
    ]);

    Sanctum::actingAs($user, ['api-access']);

    $newPassword = 'newStrongPassword123';

    $response = putJson(route('password.update'), [
        'current_password' => 'currentpassword',
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'message' => 'Contraseña actualizada correctamente'
        ]);

    $user->refresh();
    expect(Hash::check($newPassword, $user->password))->toBeTrue();
    expect($user->password_changed_at)->not->toBeNull();
});

it('rejects a password change with an incorrect current password', function () {
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('currentpassword'),
    ]);

    Sanctum::actingAs($user, ['api-access']);

    $response = putJson(route('password.update'), [
        'current_password' => 'wrongcurrentpassword',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertStatus(400);
});

it('rejects a new password equal to the current one', function () {
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password' => Hash::make('currentpassword'),
    ]);

    Sanctum::actingAs($user, ['api-access']);

    $response = putJson(route('password.update'), [
        'current_password' => 'currentpassword',
        'password' => 'currentpassword',
        'password_confirmation' => 'currentpassword',
    ]);

    $response->assertStatus(422);
});

it('validates the new password format and confirmation match', function () {
    $user = User::factory()->create([
        'rut' => '11111111-1',
    ]);
    Sanctum::actingAs($user, ['api-access']);

    $response = putJson(route('password.update'), [
        'current_password' => 'currentpassword',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);
    $response->assertStatus(422)
        ->assertJsonValidationErrorFor('password');

    $response = putJson(route('password.update'), [
        'current_password' => 'currentpassword',
        'password' => 'newpassword123',
        'password_confirmation' => 'anotherpassword123',
    ]);
    $response->assertStatus(422)->assertJsonValidationErrorFor('password');
});

it('allows checking the user\'s password status', function () {
    $user = User::factory()->create([
        'rut' => '11111111-1',
        'password_changed_at' => null,
    ]);
    Sanctum::actingAs($user, ['api-access']);

    $response = getJson(route('password.status'));
    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'data' => [
                'needs_password_change' => true,
            ]
        ]);

    $user->password_changed_at = Carbon::now();
    $user->save();

    $response = getJson(route('password.status'));
    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'data' => [
                'needs_password_change' => false,
            ]
        ]);
});
