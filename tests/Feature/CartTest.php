<?php

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    /**
     * @var \Tests\TestCase $this
     * @var \App\Models\User $this->user
     */

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('read-own-cart');
    actingAs($this->user, 'sanctum');

    $supercategory = Category::factory()->create(['level' => 1]);
    $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
    $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);
    $brand = Brand::factory()->create();

    $this->product = Product::factory()->create([
        'supercategory_id' => $supercategory->id,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'brand_id' => $brand->id
    ]);

    // Crear precio activo para el producto
    $this->price = Price::factory()->create([
        'product_id' => $this->product->id,
        'unit' => 'kg',
        'price' => 100,
        'stock' => 10,
        'is_active' => true,
        'valid_from' => now()->subDays(1),
        'valid_to' => null
    ]);
});

test('can view their own cart', function () {
    /**
     * @var \Tests\TestCase $this
     * @var \App\Models\User $this->user
     * @var \App\Models\Product $this->product
     */

    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    // Act
    $response = getJson(route('cart.index'));

    // Assert
    $response->assertOk();

    // Should have cart structure
    $data = $response->json();
    expect($data)->toHaveKey('data');
    expect($data['data'])->toHaveKey('items');
    expect($data['data'])->toHaveKey('total');
    expect($data['data']['items'])->toHaveCount(1);
    expect($data['data']['items'][0]['quantity'])->toBe(2);
});

test('requires authentication to view cart', function () {
    /**
     * @var \Tests\TestCase $this
     * @var \Illuminate\Foundation\Application $this->product
     */

    // Arrange
    $this->app['auth']->forgetUser();

    // Act
    $response = getJson(route('cart.index'));

    // Assert
    $response->assertUnauthorized();
});

test('requires "read-own-cart" permission to view cart', function () {
    // Arrange - Usuario sin permisos
    $userWithoutPermissions = User::factory()->create();
    actingAs($userWithoutPermissions, 'sanctum');

    // Act
    $response = getJson(route('cart.index'));

    // Assert
    $response->assertForbidden();
});

test('only shows cart items from authenticated user', function () {
    /**
     * @var \Tests\TestCase $this
     * @var \App\Models\User $this->user
     * @var \App\Models\Product $this->product
     */

    // Arrange
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('read-own-cart');

    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    CartItem::create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
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

test('only shows the price from the price list allowed for the user', function () {
    // Arrange
    $allowedPriceListCode = '01P';
    $otherPriceListCode = 'OTHER';

    $user = User::factory()->create([
        'prices_lists' => [$allowedPriceListCode],
    ]);
    $user->givePermissionTo('read-own-cart');
    actingAs($user, 'sanctum');

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
