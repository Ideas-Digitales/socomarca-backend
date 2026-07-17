<?php

use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\CartItemScenario;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

it('verifies that a user can add item to cart', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

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

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);
});

it('verifies that store response works according to the allowed user price lists', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $user->update(['prices_lists' => ['01P']]);

    Price::where('product_id', $product->id)->delete();
    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 100,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => '01P',
    ]);
    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 999,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => 'OTHER',
    ]);

    $data = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertCreated();
    expect($response->json('product.price'))->toBe(100);
    expect($response->json('total'))->toBe(200);
});

it('should increment item quantity when a product is in the cart', function () {
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    //Clear cart items
    CartItem::where('user_id', $user->id)->delete();

    // Arrange
    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(201);


    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ]);

    expect(CartItem::where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->where('unit', 'kg')
        ->count())->toBe(1);
});

it('should fail when adding an item without product_id', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

it('should fail when adding an item with inexistent product_id', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => 99999,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

it('should fail when trying to add an item without quantity', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('should fail when trying to add an item with a quantity lower than 1', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 0,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('should fail when trying to add an item with quantity greater than 99', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 100,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('should fail when trying to add an item without unit', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 2
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['unit']);
});

it('should delete partial item quantity from the cart', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item quantity has been removed from cart'
        ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3, // 5 - 2 = 3
        'unit' => 'kg'
    ]);
});

it('should delete an item from the cart when its quantity is 0', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item quantity has been removed from cart'
        ]);

    assertDatabaseMissing('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'unit' => 'kg'
    ]);
});

it('should return a message when an item doesn\'t exist to be deleted', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Product item not found'
        ]);
});

it('should fail when trying to deleted more quantity than the available', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $data = [
        'product_id' => $product->id,
        'quantity' => 5, // Intentar eliminar más de lo disponible
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('should fail when trying to delete an item without product_id', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

it('should fail when trying to delete an item without unit', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'quantity' => 1
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['unit']);
});

it('should fail when trying to delete an item without quantity', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $data = [
        'product_id' => $product->id,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('should prevent a user from getting another owner\'s cart', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['create-cart-items', 'delete-cart-items']);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    CartItem::create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    Sanctum::actingAs($otherUser, ['api-access']);

    $data = [
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(200);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'quantity' => 1, // 2 - 1 = 1
        'unit' => 'kg'
    ]);
});

it('should handle different units of the same product', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'g',
        'price' => 50,
        'stock' => 100,
        'is_active' => true,
        'valid_from' => now()->subDays(1),
        'valid_to' => null
    ]);

    $dataKg = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    $dataG = [
        'product_id' => $product->id,
        'quantity' => 50,
        'unit' => 'g'
    ];

    // Act
    $responseKg = postJson(route('cart-items.store'), $dataKg);
    $responseG = postJson(route('cart-items.store'), $dataG);

    // Assert
    $responseKg->assertStatus(201);
    $responseG->assertStatus(201);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'unit' => 'g'
    ]);

    expect(CartItem::where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->count())->toBe(2);
});

it('should require authentication to add items', function () {
    /** @var \Tests\TestCase $this */

    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $this->app['auth']->forgetUser();

    $data = [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    // Act
    $response = postJson(route('cart-items.store'), $data);

    // Assert
    $response->assertStatus(401);
});

it('should required authentication to delete items', function () {
    /** @var \Tests\TestCase $this */

    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    $this->app['auth']->forgetUser();

    $data = [
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ];

    // Act
    $response = deleteJson(route('cart-items.destroy'), $data);

    // Assert
    $response->assertStatus(401);
});

it('should empty cart when requested', function () {
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);

    \App\Models\CartItem::truncate();

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

    assertDatabaseCount('cart_items', 2);

    $route = route('cart.empty');

    $response = deleteJson($route);

    $response->assertStatus(200)
        ->assertJsonFragment(['message' => 'The cart has been emptied']);

    assertDatabaseMissing('cart_items', [
        'user_id' => $user->id,
    ]);
});

it('should prevent to emptying cart of another user', function () {
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

    Sanctum::actingAs($userA, ['api-access']);
    $response = deleteJson($route);

    $response->assertStatus(200)
        ->assertJsonFragment(['message' => 'The cart has been emptied']);

    // El carrito de userB debe seguir teniendo sus ítems
    assertDatabaseHas('cart_items', [
        'user_id' => $userB->id,
        'product_id' => $product->id,
    ]);
});

it('verifies that addOrderToCart response work with user price lists', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::where('user_id', $user->id)->delete();
    $user->update(['prices_lists' => ['01P']]);

    Price::where('product_id', $product->id)->delete();
    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 100,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => '01P',
    ]);
    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'kg',
        'price' => 999,
        'stock' => 10,
        'is_active' => true,
        'price_list_id' => 'OTHER',
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg',
        'price' => 100
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200);
    $item = collect($response->json('cart.items'))
        ->firstWhere('id', $product->id);

    expect($item)->not->toBeNull();
    expect($item['price'])->toBe(100);
});

it('should add products from order to the empty cart', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::where('user_id', $user->id)->delete();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    $product2 = Product::factory()->create([
        'supercategory_id' => $product->supercategory_id,
        'category_id' => $product->category_id,
        'subcategory_id' => $product->subcategory_id,
        'brand_id' => $product->brand_id
    ]);

    Price::factory()->create([
        'product_id' => $product2->id,
        'unit' => 'g',
        'price' => 50,
        'is_active' => true
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
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
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 2,
            'updated_items' => 0
        ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product2->id,
        'quantity' => 5,
        'unit' => 'g'
    ]);
});

it('should increment product quantity when the product is in the cart', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::where('user_id', $user->id)->delete();

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit' => 'kg',
        'price' => 100
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 0,
            'updated_items' => 1
        ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 5, // 2 + 3 = 5
        'unit' => 'kg'
    ]);

    expect(CartItem::where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->where('unit', 'kg')
        ->count())->toBe(1);
});

it('should handle existent and new products within the same operation', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::where('user_id', $user->id)->delete();

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'kg'
    ]);

    $product2 = Product::factory()->create([
        'supercategory_id' => $product->supercategory_id,
        'category_id' => $product->category_id,
        'subcategory_id' => $product->subcategory_id,
        'brand_id' => $product->brand_id
    ]);

    Price::factory()->create([
        'product_id' => $product2->id,
        'unit' => 'g',
        'price' => 50,
        'is_active' => true
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
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
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 1,
            'updated_items' => 1
        ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 3, // 1 + 2 = 3
        'unit' => 'kg'
    ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product2->id,
        'quantity' => 3,
        'unit' => 'g'
    ]);
});

it('should fail when adding an order without order_id', function () {
    // Act
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $response = postJson(route('cart.add-order'), []);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

it('should fail when adding an order with inexistent order_id', function () {
    // Act
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $response = postJson(route('cart.add-order'), [
        'order_id' => 99999
    ]);

    // Assert
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_id']);
});

it('should fail when adding an order that doesn\'t belong to the user', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['create-orders', 'read-own-orders']);
    $order = Order::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'completed'
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(403);
});

it('should require authentication to add order to cart', function () {
    /** @var \Tests\TestCase $this */

    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $this->app['auth']->forgetUser();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(401);
});

it('should handle order without items', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    Sanctum::actingAs($user, ['api-access']);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
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

it('should handle different units of the same product in the same order', function () {
    // Arrange
    $scenario = CartItemScenario::make();
    $user = $scenario->user;
    $product = $scenario->product;
    Sanctum::actingAs($user, ['api-access']);
    CartItem::where('user_id', $user->id)->delete();

    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => 'g',
        'price' => 30,
        'is_active' => true
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed'
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg',
        'price' => 100
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 500,
        'unit' => 'g',
        'price' => 30
    ]);

    // Act
    $response = postJson(route('cart.add-order'), [
        'order_id' => $order->id
    ]);

    // Assert
    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Productos de la orden agregados al carrito exitosamente',
            'added_items' => 2,
            'updated_items' => 0
        ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ]);

    assertDatabaseHas('cart_items', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 500,
        'unit' => 'g'
    ]);

    expect(CartItem::where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->count())->toBe(2);
});
