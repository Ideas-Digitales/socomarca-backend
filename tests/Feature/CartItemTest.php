<?php

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;

beforeEach(function () {
    // Crear usuario autenticado con permisos de customer
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['create-cart-items', 'delete-cart-items', 'create-orders']);
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

test('can add an item to cart', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response
        ->assertCreated()
        ->assertJsonStructure([
            'product' => [
                'id',
                'name',
                'price',
            ],
            'quantity',
            'unit',
            'total',
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);
});

test('can increment quantity if item already exists in cart', function () {

    //Clear cart items
    CartItem::where('user_id', $this->user->id)->delete();

    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(201);


    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ]);

    expect(CartItem::where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->where('unit', 'kg')
        ->count())->toBe(1);
});

test('fails to add item without product_id', function () {
    // Arrange
    $data = [
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

test('fails to add item with non-existent product_id', function () {
    // Arrange
    $data = [
        'product_id' => 99999,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

test('fails to add item without quantity', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('fails to add item with quantity less than 1', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 0,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('fails to add item with quantity greater than 99', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 100,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('fails to add item without unit', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 2
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['unit']);
});

test('can remove partial quantity of item from cart', function () {
    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item quantity has been removed from cart'
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3, // 5 - 2 = 3
        'unit' => 'kg'
    ]);
});

test('can remove complete item from cart when quantity reaches zero', function () {
    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item quantity has been removed from cart'
        ]);

    $this->assertDatabaseMissing('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'unit' => 'kg'
    ]);
});

test('returns message when item does not exist for removal', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item not found'
        ]);
});

test('fails to remove more quantity than available', function () {
    // Arrange
    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 5, // Intentar eliminar más de lo disponible
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('fails to remove item without product_id', function () {
    // Arrange
    $data = [
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

test('fails to remove item without unit', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 1
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['unit']);
});

test('fails to remove item without quantity', function () {
    // Arrange
    $data = [
        'product_id' => $this->product->id,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('different users cannot see items from other carts', function () {
    // Arrange
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['create-cart-items', 'delete-cart-items']);

    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    CartItem::create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $this->actingAs($otherUser, 'sanctum');

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(200);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
        'quantity' => 1, // 2 - 1 = 1
        'unit' => 'kg'
    ]);
});

test('can handle different units of the same product', function () {
    // Arrange
    Price::factory()->create([
        'product_id' => $this->product->id,
        'unit' => 'g',
        'price' => 50,
        'stock' => 100,
        'is_active' => true,
        'valid_from' => now()->subDays(1),
        'valid_to' => null
    ]);

    $dataKg = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    $dataG = [
        'product_id' => $this->product->id,
        'quantity' => 50,
        'unit' => 'g'
    ];

    // Act
    $responseKg = $this->postJson('/api/cart/items', $dataKg);
    $responseG = $this->postJson('/api/cart/items', $dataG);

    // Assert
    $responseKg->assertStatus(201);
    $responseG->assertStatus(201);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 50,
        'unit' => 'g'
    ]);

    expect(CartItem::where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->count())->toBe(2);
});

test('requires authentication to add items', function () {
    // Arrange
    $this->app['auth']->forgetUser();

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->postJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(401);
});

test('requires authentication to remove items', function () {
    // Arrange
    $this->app['auth']->forgetUser();

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = $this->deleteJson('/api/cart/items', $data);

    // Assert
    $response->assertStatus(401);
});

test('can empty their cart', function () {

    \App\Models\CartItem::truncate();

    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('delete-cart');

    $product = \App\Models\Product::factory()->create();


    \App\Models\CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    \App\Models\CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $this->assertDatabaseCount('cart_items', 2);

    $route = route('cart.empty');

    $response = $this->actingAs($user, 'sanctum')
        ->deleteJson($route);

    $response->assertStatus(200)
        ->assertJsonFragment(['message' => 'The cart has been emptied']);


    $this->assertDatabaseMissing('cart_items', [
        'user_id' => $user->id,
    ]);
});

test('customer cannot empty other users carts', function () {

    $userA = \App\Models\User::factory()->create();
    $userA->givePermissionTo('delete-cart');

    $userB = \App\Models\User::factory()->create();
    $userB->givePermissionTo('delete-cart');


    $product = \App\Models\Product::factory()->create();

    // Agrega ítems al carrito de userB
    \App\Models\CartItem::factory()->create([
        'user_id' => $userB->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    // userA intenta vaciar el carrito (la ruta solo debe vaciar su propio carrito)
    $route = route('cart.empty');

    $response = $this->actingAs($userA, 'sanctum')
        ->deleteJson($route);

    $response->assertStatus(200)
        ->assertJsonFragment(['message' => 'The cart has been emptied']);

    // El carrito de userB debe seguir teniendo sus ítems
    $this->assertDatabaseHas('cart_items', [
        'user_id' => $userB->id,
        'product_id' => $product->id,
    ]);
});

test('can add products from an order to empty cart', function () {
    // Arrange
    CartItem::where('user_id', $this->user->id)->delete();

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $this->product->category_id,
        'subcategory_id' => $this->product->subcategory_id,
        'brand_id' => $this->product->brand_id
    ]);

    Price::factory()->create([
        'product_id' => $product2->id,
        'unit' => 'g',
        'price' => 50,
        'is_active' => true
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg',
        'price' => 100
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 5,
        'unit' => 'g',
        'price' => 50
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 2,
            'updated_items' => 0
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 5,
        'unit' => 'g'
    ]);
});

test('can sum quantities when product already exists in cart', function () {
    // Arrange
    CartItem::where('user_id', $this->user->id)->delete();

    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg',
        'price' => 100
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 0,
            'updated_items' => 1
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 5, // 2 + 3 = 5
        'unit' => 'kg'
    ]);

    expect(CartItem::where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->where('unit', 'kg')
        ->count())->toBe(1);
});

test('can handle existing and new products in same operation', function () {
    // Arrange
    CartItem::where('user_id', $this->user->id)->delete();

    CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $this->product->category_id,
        'subcategory_id' => $this->product->subcategory_id,
        'brand_id' => $this->product->brand_id
    ]);

    Price::factory()->create([
        'product_id' => $product2->id,
        'unit' => 'g',
        'price' => 50,
        'is_active' => true
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg',
        'price' => 100
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 3,
        'unit' => 'g',
        'price' => 50
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 1,
            'updated_items' => 1
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3, // 1 + 2 = 3
        'unit' => 'kg'
    ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 3,
        'unit' => 'g'
    ]);
});

test('fails to add order without order_id', function () {
    // Act
    $response = $this->postJson('/api/cart/add-order', []);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

test('fails to add order with non-existent order_id', function () {
    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => 99999
    ]);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

test('fails to add order that does not belong to user', function () {
    // Arrange
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['create-orders', 'read-own-orders']);
    $order = Order::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'completed'
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(403);
});

test('requires authentication to add order to cart', function () {
    // Arrange
    $this->app['auth']->forgetUser();

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(401);
});

test('handles order without items correctly', function () {
    // Arrange
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 0,
            'updated_items' => 0
        ]);
});

test('respects different units of same product from order', function () {
    // Arrange
    CartItem::where('user_id', $this->user->id)->delete();

    Price::factory()->create([
        'product_id' => $this->product->id,
        'unit' => 'g',
        'price' => 30,
        'is_active' => true
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg',
        'price' => 100
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 500,
        'unit' => 'g',
        'price' => 30
    ]);

    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 2,
            'updated_items' => 0
        ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 500,
        'unit' => 'g'
    ]);

    expect(CartItem::where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->count())->toBe(2);
});
