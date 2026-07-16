<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\withHeader;

describe('successful updates', function () {
    it('allows an authenticated user to update only their email', function () {
        // Arrange
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'email' => 'new@example.com',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        $user->refresh();
        expect($user->email)->toBe('new@example.com');
        expect(Hash::check('CurrentPass123', $user->password))->toBeTrue();
    });

    it('allows an authenticated user to update only their password', function () {
        // Arrange
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('CurrentPass123'),
            'password_changed_at' => null,
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'password' => 'NewStrongPass456',
            'password_confirmation' => 'NewStrongPass456',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        $user->refresh();
        expect(Hash::check('NewStrongPass456', $user->password))->toBeTrue();
        expect($user->email)->toBe('user@example.com');
        expect($user->password_changed_at)->not->toBeNull();
    });

    it('allows an authenticated user to update email and password simultaneously', function () {
        // Arrange
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'email' => 'new@example.com',
            'password' => 'NewStrongPass456',
            'password_confirmation' => 'NewStrongPass456',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        $user->refresh();
        expect($user->email)->toBe('new@example.com');
        expect(Hash::check('NewStrongPass456', $user->password))->toBeTrue();
    });
});

describe('authentication', function () {
    it('rejects unauthenticated requests', function () {
        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'email' => 'new@example.com',
        ]);

        // Assert
        $response->assertStatus(401);
    });

    it('rejects the update when the current password is incorrect', function () {
        // Arrange
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'WrongPassword',
            'email' => 'new@example.com',
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson([
                'status' => false,
            ])
            ->assertJsonStructure(['errors' => ['current_password']]);

        $user->refresh();
        expect($user->email)->toBe('user@example.com');
    });
});

describe('validation', function () {
    it('requires the current_password field', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'email' => 'new@example.com',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    });

    it('rejects an invalid email format', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'email' => 'not-an-email',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects an email already used by another user', function () {
        // Arrange
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'email' => 'taken@example.com',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects a new password shorter than 8 characters', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('rejects a password confirmation mismatch', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'password' => 'NewStrongPass456',
            'password_confirmation' => 'DoesNotMatch789',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('rejects a new password equal to the current password', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        actingAs($user);

        // Act
        $response = patchJson(route('credentials.update'), [
            'current_password' => 'CurrentPass123',
            'password' => 'CurrentPass123',
            'password_confirmation' => 'CurrentPass123',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });
});

describe('token revocation', function () {
    it('revokes the other tokens when revoke_all_tokens is true', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        $currentToken = $user->createToken('current-device');
        $user->createToken('other-device');

        // Act
        $response = withHeader('Authorization', 'Bearer ' . $currentToken->plainTextToken)
            ->patchJson(route('credentials.update'), [
                'current_password' => 'CurrentPass123',
                'email' => 'new@example.com',
                'revoke_all_tokens' => true,
            ]);

        // Assert
        $response->assertStatus(200);

        $user->refresh();
        expect($user->tokens()->count())->toBe(1);
        expect($user->tokens()->first()->id)->toBe($currentToken->accessToken->id);
    });

    it('keeps the other tokens when revoke_all_tokens is false', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPass123'),
        ]);
        $currentToken = $user->createToken('current-device');
        $user->createToken('other-device');

        // Act
        $response = withHeader('Authorization', 'Bearer ' . $currentToken->plainTextToken)
            ->patchJson(route('credentials.update'), [
                'current_password' => 'CurrentPass123',
                'email' => 'new@example.com',
                'revoke_all_tokens' => false,
            ]);

        // Assert
        $response->assertStatus(200);
        expect($user->tokens()->count())->toBe(2);
    });
});
