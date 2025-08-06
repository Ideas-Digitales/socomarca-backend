<?php

use App\Models\Subcategory;
use App\Models\User;

beforeEach(function () {
    $this->user = createUser();
    $this->category = \App\Models\Category::factory()->create();
    $this->subcategory = \App\Models\Subcategory::factory()->create([
        'category_id' => $this->category->id,
    ]);
});

describe('Subcategory API', function () {
    describe('Authorization', function () {
        it('should require authentication for index', function () {
            $response = $this->getJson(route('subcategories.index'));
            $response->assertStatus(401);
        });

        it('should require permission for index', function () {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');
            $response = $this->getJson(route('subcategories.index'));
            $response->assertStatus(403);
        });

        it('should allow access to index with permission', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-all-subcategories');
            $this->actingAs($user, 'sanctum');
            $response = $this->getJson(route('subcategories.index'));
            $response->assertStatus(200);
        });

        it('should require authentication for show', function () {
            $response = $this->getJson(route('subcategories.show', ['subcategory' => $this->subcategory->id]));
            $response->assertStatus(401);
        });

        it('should require permission for show', function () {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');
            $response = $this->getJson(route('subcategories.show', ['subcategory' => $this->subcategory->id]));
            $response->assertStatus(403);
        });

        it('should allow access to show with permission', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-all-subcategories');
            $this->actingAs($user, 'sanctum');
            $response = $this->getJson(route('subcategories.show', ['subcategory' => $this->subcategory->id]));
            $response->assertStatus(200);
        });
    });

    describe('Functional', function () {
        it('should return 401 if token is missing', function () {
            $response = $this->withHeaders(['Accept' => 'application/json'])
                ->get(route('subcategories.index'));
            $response->assertStatus(401);
        });

        it('should return 200 and correct structure for index', function () {
            $this->user->givePermissionTo('read-all-subcategories');
            $response = $this->actingAs($this->user, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->get(route('subcategories.index'));

            $response
                ->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        [
                            'id',
                            'name',
                            'description',
                            'code',
                            'level',
                            'key',
                            'category' => [
                                'id',
                                'name',
                                'description',
                                'code',
                                'level',
                                'key',
                                'created_at',
                                'updated_at',
                            ],
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ]);
        });

        it('should return 404 for non-existent subcategory', function () {
            $this->user->givePermissionTo('read-all-subcategories');
            $id = $this->subcategory->id;
            Subcategory::truncate();

            $response = $this->actingAs($this->user, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->get(route('subcategories.show', ['subcategory' => $id]));

            $response->assertStatus(404);
        });
    });
});
