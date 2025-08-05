<?php

use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

describe('Favorites read endpoint', function () {
    it('should fail when requesting without authentication', function () {
        $route = route('favorites.index');
        $this->getJson($route)->assertStatus(401);
    });

    it('should return a successful list of user favorites with products', function () {

        $route = route('favorites.index');
        $user = User::factory()
            ->has(
                FavoriteList::factory()
                    ->has(
                        Favorite::factory()
                            ->for(
                                Product::factory(['name' => 'Product 1'])
                                    ->has(Price::factory()->count(2), 'prices'),
                                'product'
                            )
                            ->for(
                                Product::factory(['name' => 'Product 2'])
                                    ->has(Price::factory()->count(2), 'prices'),
                                'product'
                            )
                    )->has(
                        Favorite::factory()
                            ->for(
                                Product::factory(['name' => 'Product 3'])
                                    ->has(Price::factory()->count(2), 'prices'),
                                'product'
                            )
                            ->for(
                                Product::factory(['name' => 'Product 4'])
                                    ->has(Price::factory()->count(2), 'prices'),
                                'product'
                            )
                    )
                    ->count(2),
                'favoritesList'
            )
            ->create();
        $user->givePermissionTo('read-own-favorites');

        $this->actingAs($user, 'sanctum')
            ->getJson($route)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'name',
                        'favorites' => [
                            [
                                'id',
                                'product' => [
                                    'id',
                                    'name',
                                    'category' => [
                                        'id',
                                        'name',
                                    ],
                                    'subcategory' => [
                                        'id',
                                        'name',
                                    ],
                                    'brand' => [
                                        'id',
                                        'name',
                                    ],
                                    'unit',
                                    'price',
                                    'stock',
                                    'image',
                                    'sku',
                                ],
                            ]
                        ],
                    ],
                ],
            ])
            ->assertJson(
                fn(AssertableJson $json) =>
                $json
                    ->has('data')
                    ->has('data.0.favorites')
                    ->where('data.0.favorites.0.id', fn($id) => Favorite::find($id)->favoriteList->user->id == $user->id)
                    ->where('data.0.favorites.1.id', fn($id) => Favorite::find($id)->favoriteList->user->id == $user->id)
                    ->where('data.1.favorites.0.id', fn($id) => Favorite::find($id)->favoriteList->user->id == $user->id)
                    ->where('data.1.favorites.1.id', fn($id) => Favorite::find($id)->favoriteList->user->id == $user->id)
                    ->etc()
            );
    });
});

describe('Favorite creation endpoint', function () {
    it('should fail because of validation and authorization failure', function () {
        $user = User::factory()->create();
        $route = route('favorites.store');
        $user->givePermissionTo('create-favorites');

        $user2 = User::factory()
            ->has(
                FavoriteList::factory(),
                'favoritesList'
            )
            ->create();
        $user2->givePermissionTo('create-favorites');

        $product = Product::factory()
            ->has(
                Price::factory(['unit' => 'kg']),
                'prices'
            )
            ->create();

        $this->actingAs($user, 'sanctum')
            ->postJson($route, [
                'product_id' => Product::factory()->create()->id,
                'favorite_list_id' => FavoriteList::factory()->create()->id,
            ])
            ->assertForbidden();

        $this->actingAs($user2, 'sanctum')
            ->postJson($route, [
                'favorite_list_id' => $user2->favoritesList()->first()->id,
                'unit' => 'lt'
            ])
            ->assertInvalid(['product_id', 'unit']);

        $this->actingAs($user2, 'sanctum')
            ->postJson($route, [
                'favorite_list_id' => $user2->favoritesList()->first()->id,
                'product_id' => $product->id,
                'unit' => 'lt'
            ])
            ->assertInvalid(['unit']);
    });

    it('should store a new favorite', function () {
        $route = route('favorites.store');
        $user = User::factory()->has(
            FavoriteList::factory(),
            'favoritesList'
        )
            ->create();
        $user->givePermissionTo(['read-own-favorites', 'create-favorites']);

        $unit = 'kg';
        $product = Product::factory()
            ->has(
                Price::factory(['unit' => $unit]),
                'prices'
            )
            ->create();

        $this->actingAs($user, 'sanctum')
            ->postJson($route, [
                'favorite_list_id' => $user->favoritesList()->first()->id,
                'product_id' => $product->id,
                'unit' => $unit
            ])
            ->assertCreated();

        $route = route('favorites.index');

        $this->actingAs($user, 'sanctum')
            ->getJson($route)
            ->assertOk()
            ->assertJson(
                fn(AssertableJson $json) =>
                $json
                    ->has('data')
                    ->has('data.0.favorites')
                    ->where('data.0.favorites.0.id', fn($id) => Favorite::find($id)->favoriteList->user->id == $user->id)
                    ->where('data.0.favorites.0.product.id', fn($id) => $product->id == $id)
                    ->where('data.0.favorites.0.product.unit', fn($u) => $unit == $u)
                    ->etc()
            );
    });
});

describe('Favorite deletion endpoint', function () {
    it('favorite deleted', function () {
        $route = route('favorites.store');
        $user = User::factory()->has(
            FavoriteList::factory(),
            'favoritesList'
        )
            ->create();
        $user->givePermissionTo(['read-own-favorites', 'create-favorites', 'delete-favorites']);
        $unit = 'kg';
        $product = Product::factory()
            ->has(
                Price::factory(['unit' => $unit]),
                'prices'
            )
            ->create();

        $this
            ->actingAs($user, 'sanctum')
            ->postJson($route, [
                'favorite_list_id' => $user->favoritesList()->first()->id,
                'product_id' => $product->id,
                'unit' => $unit
            ])
            ->assertCreated();

        $user->refresh();
        $favoritesQuantity = $user->favoritesList()->first()->favorites()->count();

        expect($favoritesQuantity == 1)->toBeTrue();

        $route = route('favorites.destroy', [
            'favorite' => $user->favoritesList()->first()->favorites()->first()->id,
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->deleteJson($route)
            ->assertOk();

        $user->refresh();
        $favoritesQuantity = $user->favoritesList()->first()->favorites()->count();

        expect($favoritesQuantity == 0)->toBeTrue();
    });
});
