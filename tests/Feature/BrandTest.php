<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

describe('Brand authorization', function () {
    it('should require authentication for index', function () {
        $response = getJson(route('brands.index'));
        $response->assertStatus(401);
    });

    it('should require permission for index', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);
        $response = getJson(route('brands.index'));
        $response->assertStatus(403);
    });

    it('should allow access to index with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-brands');
        Sanctum::actingAs($user, ['api-access']);
        $response = getJson(route('brands.index'));
        $response->assertStatus(200);
    });
});
