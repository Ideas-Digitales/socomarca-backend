<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('update-system-config');
    $this->user = User::factory()->create();
});

describe('FirebaseConfig API', function () {
    it('should require authentication for showConfig', function () {
        $response = $this->getJson(route('firebase.config.show'));
        $response->assertStatus(401);
    });

    it('should require permission for showConfig', function () {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson(route('firebase.config.show'));
        $response->assertStatus(403);
    });

    it('should return config for authorized user', function () {
        $this->actingAs($this->admin, 'sanctum');
        $response = $this->getJson(route('firebase.config.show'));
        // Puede ser 200 o 404 si no hay config, pero nunca 401/403
        $response->assertStatus(in_array($response->status(), [200, 404]) ? $response->status() : 200);
    });

    it('should require authentication for update', function () {
        $response = $this->putJson(route('firebase.config.update'), [
            'project_id' => 'test',
            'private_key' => 'key',
        ]);
        $response->assertStatus(401);
    });

    it('should require permission for update', function () {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->putJson(route('firebase.config.update'), [
            'project_id' => 'test',
            'private_key' => 'key',
        ]);
        $response->assertStatus(403);
    });

    it('should update config for authorized user', function () {
        $this->actingAs($this->admin, 'sanctum');
        $response = $this->putJson(route('firebase.config.update'), [
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
    it('should require authentication', function () {
        $response = $this->putJson(route('firebase.fcm-token'), [
            'fcm_token' => 'test_token',
        ]);
        $response->assertStatus(401);
    });

    it('should update fcm_token for authenticated user', function () {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->putJson(route('firebase.fcm-token'), [
            'fcm_token' => 'test_token',
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'FCM Token saved.']);
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'fcm_token' => 'test_token',
        ]);
    });

    it('should validate fcm_token is required', function () {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->putJson(route('firebase.fcm-token'), []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('fcm_token');
    });
});