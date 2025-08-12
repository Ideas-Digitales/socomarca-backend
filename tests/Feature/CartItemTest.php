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

test('puede agregar un item al carrito', function () {
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

test('puede incrementar cantidad si item ya existe en carrito', function () {

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

test('falla al agregar item sin product_id', function () {
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

test('falla al agregar item con product_id inexistente', function () {
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

test('falla al agregar item sin quantity', function () {
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

test('falla al agregar item con quantity menor a 1', function () {
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

test('falla al agregar item con quantity mayor a 99', function () {
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

test('falla al agregar item sin unit', function () {
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

test('puede eliminar cantidad parcial de item del carrito', function () {
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

test('puede eliminar item completo del carrito cuando quantity llega a cero', function () {
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

test('retorna mensaje cuando item no existe para eliminar', function () {
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

test('falla al eliminar mas cantidad de la disponible', function () {
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

test('falla al eliminar item sin product_id', function () {
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

test('falla al eliminar item sin unit', function () {
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

test('falla al eliminar item sin quantity', function () {
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

test('usuarios diferentes no pueden ver items de otros carritos', function () {
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

test('puede manejar diferentes unidades del mismo producto', function () {
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

test('requiere autenticacion para agregar items', function () {
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

test('requiere autenticacion para eliminar items', function () {
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

test('vaciar su carrito', function () {

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

test('customer no puede vaciar carros de otros', function () {

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

test('puede agregar productos de una orden al carrito vacío', function () {
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

test('puede sumar cantidades cuando el producto ya existe en el carrito', function () {
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

test('puede manejar productos existentes y nuevos en la misma operación', function () {
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

test('falla al agregar orden sin order_id', function () {
    // Act
    $response = $this->postJson('/api/cart/add-order', []);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

test('falla al agregar orden con order_id inexistente', function () {
    // Act
    $response = $this->postJson('/api/cart/add-order', [
        'order_id' => 99999
    ]);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

test('falla al agregar orden que no pertenece al usuario', function () {
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

test('requiere autenticación para agregar orden al carrito', function () {
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

test('maneja orden sin items correctamente', function () {
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

test('respeta diferentes unidades del mismo producto de la orden', function () {
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
