<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'update-system-config']);
});

describe('Firebase config endpoint', function () {
    test('unauthorized without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $payload = [
            'type' => 'service_account',
            'project_id' => 'proj-1',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'client_email' => 'sa@proj.iam.gserviceaccount.com',
        ];

        $resp = $this->putJson(route('firebase.config.update'), $payload);
        $resp->assertStatus(403);
    });

    test('validation errors for missing fields', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('update-system-config');
        $this->actingAs($user, 'sanctum');

        $payload = [
            'type' => 'service_account',
            // missing project_id, private_key, client_email
        ];

        $resp = $this->putJson(route('firebase.config.update'), $payload);
        $resp->assertStatus(422)
             ->assertJsonValidationErrors(['project_id', 'private_key', 'client_email']);
    });

    test('stores credentials file on success', function () {
        Storage::fake('local');

        $user = User::factory()->create();
        $user->givePermissionTo('update-system-config');
        $this->actingAs($user, 'sanctum');

        $payload = [
            'type' => 'service_account',
            'project_id' => 'proj-1',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'client_email' => 'sa@proj.iam.gserviceaccount.com',
            'other_field' => 'value'
        ];

        $resp = $this->putJson(route('firebase.config.update'), $payload);
        $resp->assertStatus(200)->assertJson(['message' => 'Firebase config saved']);

        Storage::disk('local')->assertExists('firebase/credentials.json');

        $stored = Storage::disk('local')->get('firebase/credentials.json');
        $storedArray = json_decode($stored, true);
        expect($storedArray)->toBeArray();

        // Normalizar private_key (el middleware TrimStrings puede recortar saltos de línea)
        $payloadNormalized = $payload;
        $storedNormalized = $storedArray;

        if (isset($payloadNormalized['private_key'])) {
            $payloadNormalized['private_key'] = rtrim($payloadNormalized['private_key'], "\r\n");
        }
        if (isset($storedNormalized['private_key'])) {
            $storedNormalized['private_key'] = rtrim($storedNormalized['private_key'], "\r\n");
        }

        expect($storedNormalized)->toEqual($payloadNormalized);
    });
});