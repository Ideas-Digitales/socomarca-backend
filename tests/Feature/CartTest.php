<?php

use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\CartScenario;

use function Pest\Laravel\getJson;

it('returns the authenticated user\'s cart', function () {
    $scenario = CartScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);

    CartItem::create([
        'user_id' => $scenario->user->id,
        'product_id' => $scenario->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $response = getJson(route('cart.index'));

    $response->assertOk();

    $data = $response->json();
    expect($data)->toHaveKey('data');
    expect($data['data'])->toHaveKey('items');
    expect($data['data'])->toHaveKey('total');
    expect($data['data']['items'])->toHaveCount(1);
    expect($data['data']['items'][0]['quantity'])->toBe(2);
});

it('requires authentication to view the cart', function () {
    $response = getJson(route('cart.index'));

    $response->assertUnauthorized();
});

it('requires the "read-own-cart" permission to view the cart', function () {
    $userWithoutPermissions = User::factory()->create();
    Sanctum::actingAs($userWithoutPermissions, ['api-access']);

    $response = getJson(route('cart.index'));

    $response->assertForbidden();
});

it('only returns cart items belonging to the authenticated user', function () {
    // Arrange
    $scenario = CartScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $otherUser = createUserWithPermissions(['read-own-cart']);

    CartItem::create([
        'user_id' => $scenario->user->id,
        'product_id' => $scenario->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    CartItem::create([
        'user_id' => $otherUser->id,
        'product_id' => $scenario->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    // Act
    $response = getJson(route('cart.index'));

    // Assert
    $response->assertOk()
        ->assertJsonCount(1, 'data.items');

    $data = $response->json('data.items');
    expect($data[0]['quantity'])->toBe(2);
});

it('only returns the price from the price list allowed for the user', function () {
    // Arrange
    $allowedPriceListCode = '01P';
    $otherPriceListCode = 'OTHER';

    $user = User::factory()->create([
        'prices_lists' => [$allowedPriceListCode],
    ]);
    $user->givePermissionTo('read-own-cart');
    Sanctum::actingAs($user, ['api-access']);

    $product = Product::factory()->create();

    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 100,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => $allowedPriceListCode,
    ]);

    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 999,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => $otherPriceListCode,
    ]);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'kg',
    ]);

    // Act
    $response = getJson(route('cart.index'));

    // Assert
    $response->assertOk();
    $item = $response->json('data.items.0');
    expect($item['price'])->toBe(100);
});
