<?php

use App\Models\FavoriteList;
use App\Models\User;

describe('FavoriteList Endpoints', function() {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('GET /api/favorites-list', function () {
        it('should require authentication', function () {
            $route = route('favorites-list.index');
            $this->getJson($route)->assertStatus(401);
        });

        it('should successfully return user favorites lists', function () {
            $user = User::factory()->has(FavoriteList::factory(), 'favoritesList')
                ->create();
            $user->givePermissionTo('read-own-favorites-list');
            $route = route('favorites-list.index');
            $favoriteList = $user->favoritesList()->first();

            $response = $this->actingAs($user, 'sanctum')
                ->getJson($route);

            $response
                ->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        [
                            'id',
                            'name',
                            'user_id',
                        ],
                    ],
                ])
                ->assertJsonFragment([
                    'id' => $favoriteList->id,
                    'name' => $favoriteList->name,
                ]);
        });
    });

    describe('GET /api/favorites-list/{id}', function () {
        it('should return 404 when favorite list not found', function () {
            $route = route('favorites-list.show', ['favoriteList' => 4304993]);
            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertNotFound();
        });
    });

    describe('POST /api/favorites-list', function () {
        it('should validate required fields', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('create-favorites-list');
            $route = route('favorites-list.store');

            $this->actingAs($user, 'sanctum')
                ->postJson($route)
                ->assertInvalid(['name']);
        });

        it('should successfully store new favorite list', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['create-favorites-list', 'read-own-favorites-list']);
            $route = route('favorites-list.store');

            $this->actingAs($user, 'sanctum')
                ->postJson($route, ['name' => 'Nueva lista favorita'])
                ->assertJsonStructure([
                    "name",
                    "favorites" => [],
                    "id",
                ])
                ->assertCreated();

            $route = route('favorites-list.index');
            $newList = $this->actingAs($user, 'sanctum')
                ->getJson($route)
                ->json('data.0');

            $user = User::find($user->id);
            $list = $user->favoritesList()->first();
            expect($newList['name'] == $list->name)->toBeTrue();
        });
    });

    describe('PUT /api/favorites-list/{id}', function () {
        it('should successfully update favorite list', function () {
            $user = User::factory()->has(FavoriteList::factory(), 'favoritesList')
                ->create();
            $user->givePermissionTo(['read-own-favorites-list', 'update-favorites-list']);
            $route = route('favorites-list.update', [
                'favoriteList' => $user->favoritesList()->first()->id
            ]);
            $newListName = 'Nueva lista de favoritos actualizada';

            $this->actingAs($user, 'sanctum')
                ->putJson($route, ['name' => $newListName])
                ->assertOk();

            $list = FavoriteList::find($user->favoritesList()->first()->id);
            expect($list->name == $newListName)->toBeTrue();
        });
    });

    describe('DELETE /api/favorites-list/{id}', function () {
        it('should require authentication', function () {
            $favoriteList = FavoriteList::factory()->create();
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            $this->deleteJson($route)->assertUnauthorized();
        });

        it('should require proper permissions', function () {
            $user = User::factory()->create();
            $favoriteList = FavoriteList::factory()->create(['user_id' => $user->id]);
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            $this->actingAs($user, 'sanctum')
                ->deleteJson($route)
                ->assertForbidden();
        });

        it('should not allow deleting other users favorite lists', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('delete-favorites-list');

            $otherUserList = FavoriteList::factory()->create([
                'user_id' => User::factory()->create()->id
            ]);

            $route = route('favorites-list.destroy', ['favoriteList' => $otherUserList->id]);

            $this->actingAs($user, 'sanctum')
                ->deleteJson($route)
                ->assertForbidden();
        });

        it('should return 404 when favorite list not found', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['read-own-favorites-list', 'delete-favorites-list']);

            $route = route('favorites-list.destroy', ['favoriteList' => 99999]);

            $this->actingAs($user, 'sanctum')
                ->deleteJson($route)
                ->assertNotFound();
        });

        it('should successfully delete favorite list', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['read-own-favorites-list', 'delete-favorites-list']);

            $favoriteList = FavoriteList::factory()->create(['user_id' => $user->id]);
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            $this->actingAs($user, 'sanctum')
                ->deleteJson($route)
                ->assertOk();

            $this->assertDatabaseMissing('favorites_list', [
                'id' => $favoriteList->id
            ]);
        });
    });
});
