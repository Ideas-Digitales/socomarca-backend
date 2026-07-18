<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

describe('FirebaseConfig API', function () {
    it('requires authentication for showConfig', function () {
        $response = getJson(route('firebase.config.show'));
        $response->assertStatus(401);
    });

    it('requires permission for showConfig', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = getJson(route('firebase.config.show'));
        $response->assertStatus(403);
    });

    it('returns 404 when FIREBASE_CREDENTIALS is not configured', function () {
        $admin = createUserWithPermissions(['update-system-config']);
        Sanctum::actingAs($admin, ['api-access']);

        $response = getJson(route('firebase.config.show'));

        $response->assertStatus(404)
            ->assertJson(['message' => 'FIREBASE_CREDENTIALS env not set']);
    });

    it('requires authentication for update', function () {
        $response = putJson(route('firebase.config.update'), [
            'project_id' => 'test',
            'private_key' => 'key',
        ]);
        $response->assertStatus(401);
    });

    it('requires permission for update', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = putJson(route('firebase.config.update'), [
            'project_id' => 'test',
            'private_key' => 'key',
        ]);
        $response->assertStatus(403);
    });

    it('updates config for authorized user', function () {
        $admin = createUserWithPermissions(['update-system-config']);
        Sanctum::actingAs($admin, ['api-access']);

        $response = putJson(route('firebase.config.update'), [
            'type' => 'service_account',
            'project_id' => 'test',
            'private_key' => 'key',
            'client_email' => 'test@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Firebase config saved']);
    });
});

describe('FCM Token update', function () {
    it('requires authentication', function () {
        $response = putJson(route('firebase.fcm-token'), [
            'fcm_token' => 'test_token',
        ]);
        $response->assertStatus(401);
    });

    it('updates fcm_token for authenticated user', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = putJson(route('firebase.fcm-token'), [
            'fcm_token' => 'test_token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'FCM Token saved.']);
        assertDatabaseHas('users', [
            'id' => $user->id,
            'fcm_token' => 'test_token',
        ]);
    });

    it('validates fcm_token is required', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = putJson(route('firebase.fcm-token'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('fcm_token');
    });
});
