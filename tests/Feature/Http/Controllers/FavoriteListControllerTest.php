<?php

use App\Models\FavoriteList;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

describe('FavoriteList Endpoints', function () {
    describe('GET /api/favorites-list', function () {
        it('should require authentication', function () {
            $route = route('favorites-list.index');
            getJson($route)->assertStatus(401);
        });

        it('should successfully return user favorites lists', function () {
            $user = User::factory()->has(FavoriteList::factory(), 'favoritesList')
                ->create();
            $user->givePermissionTo('read-own-favorites-list');
            $route = route('favorites-list.index');
            $favoriteList = $user->favoritesList()->first();

            Sanctum::actingAs($user, ['api-access']);
            $response = getJson($route);

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
            $user = User::factory()->create();
            $route = route('favorites-list.show', ['favoriteList' => 4304993]);
            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertNotFound();
        });
    });

    describe('POST /api/favorites-list', function () {
        it('should validate required fields', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('create-favorites-list');
            $route = route('favorites-list.store');

            Sanctum::actingAs($user, ['api-access']);
            postJson($route)
                ->assertInvalid(['name']);
        });

        it('should successfully store new favorite list', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['create-favorites-list', 'read-own-favorites-list']);
            $route = route('favorites-list.store');

            Sanctum::actingAs($user, ['api-access']);
            postJson($route, ['name' => 'Nueva lista favorita'])
                ->assertJsonStructure([
                    "name",
                    "favorites" => [],
                    "id",
                ])
                ->assertCreated();

            $route = route('favorites-list.index');
            Sanctum::actingAs($user, ['api-access']);
            $newList = getJson($route)
                ->json('data.0');

            $user = User::find($user->id);
            $list = $user->favoritesList()->first();
            expect($newList['name'] == $list->name)->toBeTrue();
        });
    });

    describe('PUT /api/favorites-list/{favoriteList}', function () {
        it('should successfully update favorite list', function () {
            $user = User::factory()->has(FavoriteList::factory(), 'favoritesList')
                ->create();
            $user->givePermissionTo(['read-own-favorites-list', 'update-favorites-list']);
            $route = route('favorites-list.update', [
                'favoriteList' => $user->favoritesList()->first()->id
            ]);
            $newListName = 'Nueva lista de favoritos actualizada';

            Sanctum::actingAs($user, ['api-access']);
            putJson($route, ['name' => $newListName])
                ->assertOk();

            $list = FavoriteList::find($user->favoritesList()->first()->id);
            expect($list->name == $newListName)->toBeTrue();
        });
    });

    describe('DELETE /api/favorites-list/{id}', function () {
        it('should require authentication', function () {
            $favoriteList = FavoriteList::factory()->create();
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            deleteJson($route)->assertUnauthorized();
        });

        it('should require proper permissions', function () {
            $user = User::factory()->create();
            $favoriteList = FavoriteList::factory()->create(['user_id' => $user->id]);
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            Sanctum::actingAs($user, ['api-access']);
            deleteJson($route)
                ->assertForbidden();
        });

        it('should not allow deleting other users favorite lists', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('delete-favorites-list');

            $otherUserList = FavoriteList::factory()->create([
                'user_id' => User::factory()->create()->id
            ]);

            $route = route('favorites-list.destroy', ['favoriteList' => $otherUserList->id]);

            Sanctum::actingAs($user, ['api-access']);
            deleteJson($route)
                ->assertForbidden();
        });

        it('should return 404 when favorite list not found', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['read-own-favorites-list', 'delete-favorites-list']);

            $route = route('favorites-list.destroy', ['favoriteList' => 99999]);

            Sanctum::actingAs($user, ['api-access']);
            deleteJson($route)
                ->assertNotFound();
        });

        it('should successfully delete favorite list', function () {
            $user = User::factory()->create();
            $user->givePermissionTo(['read-own-favorites-list', 'delete-favorites-list']);

            $favoriteList = FavoriteList::factory()->create(['user_id' => $user->id]);
            $route = route('favorites-list.destroy', ['favoriteList' => $favoriteList->id]);

            Sanctum::actingAs($user, ['api-access']);
            deleteJson($route)
                ->assertOk();

            assertDatabaseMissing('favorites_list', [
                'id' => $favoriteList->id
            ]);
        });
    });
});
