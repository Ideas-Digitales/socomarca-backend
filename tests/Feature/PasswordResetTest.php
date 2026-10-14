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

use function Pest\Laravel\getJson;
use function Pest\Laravel\instance;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

describe('password reset request', function () {
    it('sends a temporary password to the email of an active user', function () {
        Mail::fake();
        Event::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $newPassword = fake()->password();
        $passwordDto = new Password($newPassword, Hash::make($newPassword));
        instance(
            PasswordGeneratorService::class,
            Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($passwordDto) {
                $mock->expects('generate')->andReturn($passwordDto);
            })
        );

        $maskedEmail = Str::maskEmail('test@example.com');

        postJson(route('auth.password.restore'), [
            'email' => ' Test@Example.com ',
        ])
            ->assertOk()
            ->assertExactJson([
                'message' => __('auth.password_reset', ['email' => $maskedEmail]),
                'data' => ['email' => $maskedEmail],
            ]);

        $user->refresh();
        expect($user->password_changed_at)->toBeNull()
            ->and(Hash::check($passwordDto->password, $user->password))->toBeTrue();

        Mail::assertQueued(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo('test@example.com'));
    });

    it('requires the email', function () {
        postJson(route('auth.password.restore'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('responds the same without sending the password when the email does not belong to exactly one active user', function (Closure $arrange) {
        Mail::fake();
        $users = $arrange();
        $passwords = array_map(fn (User $user) => $user->password, $users);

        $maskedEmail = Str::maskEmail('test@example.com');

        postJson(route('auth.password.restore'), [
            'email' => 'test@example.com',
        ])
            ->assertOk()
            ->assertExactJson([
                'message' => __('auth.password_reset', ['email' => $maskedEmail]),
                'data' => ['email' => $maskedEmail],
            ]);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        foreach ($users as $index => $user) {
            expect($user->fresh()->password)->toBe($passwords[$index]);
        }
    })->with([
        'unregistered email' => fn () => [],
        'inactive user' => fn () => [
            User::factory()->create(['email' => 'test@example.com', 'is_active' => false]),
        ],
        'email of several active users' => fn () => [
            createSyncedCustomer(['email' => 'test@example.com', 'branch_code' => 'CM']),
            createSyncedCustomer(['email' => 'test@example.com', 'branch_code' => 'LO']),
        ],
    ]);

    it('ignores inactive users when checking for duplicated emails', function () {
        Mail::fake();

        $user = createSyncedCustomer(['email' => 'test@example.com', 'branch_code' => 'CM']);
        createSyncedCustomer(['email' => 'test@example.com', 'branch_code' => 'LO', 'is_active' => false]);

        postJson(route('auth.password.restore'), [
            'email' => 'test@example.com',
        ])->assertOk();

        Mail::assertQueued(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->user->is($user));
    });
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
