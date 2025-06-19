<?php

use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('query of user favorites failed authentication with no user', function () {
    $route = route('favorites.index');
    $this->getJson($route)->assertStatus(401);
});

test('successful query of user favorites with products', function () {
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

test('favorite store error because of validation and authorization failure', function () {
    $user = User::factory()->create();
    $route = route('favorites.store');

    $user2 = User::factory()
        ->has(
            FavoriteList::factory(),
            'favoritesList'
        )
        ->create();

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

test('new favorite succesfully stored', function () {
    $route = route('favorites.store');
    $user = User::factory()->has(
        FavoriteList::factory(),
        'favoritesList'
    )
        ->create();

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

test('favorite deleted', function () {
    $route = route('favorites.store');
    $user = User::factory()->has(
        FavoriteList::factory(),
        'favoritesList'
    )
        ->create();
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
        'id' => $user->favoritesList()->first()->favorites()->first()->id,
    ]);

    $this
        ->actingAs($user, 'sanctum')
        ->deleteJson($route)
        ->assertOk();

    $user->refresh();
    $favoritesQuantity = $user->favoritesList()->first()->favorites()->count();

    expect($favoritesQuantity == 0)->toBeTrue();
});
