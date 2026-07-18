<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

it('allows an admin user to list permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    Sanctum::actingAs($user, ['api-access']);

    $response = getJson(route('permissions.index'));

    $response->assertStatus(200)
        ->assertJsonStructure([
            [
                'id',
                'name',
            ]
        ]);
});

it('forbids a supervisor from listing permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('supervisor');
    Sanctum::actingAs($user, ['api-access']);

    $response = getJson(route('permissions.index'));

    $response->assertForbidden();
});
