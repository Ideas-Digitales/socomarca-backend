<?php

use App\Models\Brand;
use App\Models\User;

beforeEach(function () {
    $this->user = createUser();
    $this->brand = Brand::factory()->create();
});

describe('Brand authorization', function () {
    it('should require authentication for index', function () {
        $response = $this->getJson(route('brands.index'));
        $response->assertStatus(401);
    });

    it('should require permission for index', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $response = $this->getJson(route('brands.index'));
        $response->assertStatus(403);
    });

    it('should allow access to index with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-brands');
        $this->actingAs($user, 'sanctum');
        $response = $this->getJson(route('brands.index'));
        $response->assertStatus(200);
    });
});