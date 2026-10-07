<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

function bearer(string $plainTextToken): array
{
    return ['Authorization' => "Bearer {$plainTextToken}"];
}

describe('API access with tokens of inactive users', function () {
    it('accepts the token of an active user', function () {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('device', ['api-access'])->plainTextToken;

        getJson(route('auth.check.token'), bearer($token))->assertOk();
    });

    it('rejects a token issued before the user was deactivated', function () {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('device', ['api-access'])->plainTextToken;

        // Bypass the model events, so that the token is not revoked.
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        getJson(route('auth.check.token'), bearer($token))->assertUnauthorized();
    });
});

describe('token revocation on deactivation', function () {
    it('revokes the tokens when the user is deactivated', function () {
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('device-1', ['api-access']);
        $user->createToken('device-2', ['api-access']);

        $user->update(['is_active' => false]);

        expect($user->tokens()->count())->toBe(0);
    });

    it('keeps the tokens when the user is updated without being deactivated', function (array $attributes) {
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('device', ['api-access']);

        $user->update($attributes);

        expect($user->tokens()->count())->toBe(1);
    })->with([
        'other attribute' => [['name' => 'New Name']],
        'still active' => [['is_active' => true]],
    ]);

    it('revokes the tokens when an admin deactivates the user', function () {
        Notification::fake();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('device', ['api-access']);

        Sanctum::actingAs($admin, ['api-access']);
        patchJson(route('users.update', ['user' => $user->id]), ['is_active' => false])
            ->assertOk();

        expect($user->fresh()->is_active)->toBeFalse()
            ->and($user->tokens()->count())->toBe(0);
    });
});
