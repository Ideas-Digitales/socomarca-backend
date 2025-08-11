<?php

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;

beforeEach(function () {
    // Crear usuario autenticado con permisos de customer
    $this->user = User::factory()->create();
    $this->user->assignRole('customer');
    $this->actingAs($this->user, 'sanctum');

    // Crear datos necesarios para los productos
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->create([
        'category_id' => $category->id
    ]);
    $brand = Brand::factory()->create();

    $this->product = Product::factory()->create([
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
    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    // Act
    $response = $this->getJson(route('cart.index'));

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
    // Arrange
    $this->app['auth']->forgetUser();

    // Act
    $response = $this->getJson(route('cart.index'));

    // Assert
    $response->assertUnauthorized();
});

test('requires permissions to view cart', function () {
    // Arrange - Usuario sin permisos
    $userWithoutPermissions = User::factory()->create();
    $this->actingAs($userWithoutPermissions, 'sanctum');

    // Act
    $response = $this->getJson(route('cart.index'));

    // Assert
    $response->assertForbidden();
});

test('only shows cart items from authenticated user', function () {
    // Arrange
    $otherUser = User::factory()->create();
    $otherUser->assignRole('customer');

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
    $response = $this->getJson(route('cart.index'));

    // Assert
    $response->assertOk()
        ->assertJsonCount(1, 'data.items');
    
    $data = $response->json('data.items');
    expect($data[0]['quantity'])->toBe(2);
});