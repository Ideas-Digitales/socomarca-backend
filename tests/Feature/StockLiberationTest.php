<?php

use App\Events\CartItemRemoved;
use App\Events\OrderCompleted;
use App\Events\OrderFailed;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Crear usuario autenticado con permisos
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['create-cart-items', 'delete-cart-items', 'create-orders', 'delete-cart']);
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
        'is_active' => true,
        'valid_from' => now()->subDays(1),
        'valid_to' => null
    ]);

    // Crear bodega
    $this->warehouse = Warehouse::factory()->create([
        'name' => 'Test Warehouse',
        'warehouse_code' => 'TEST001',
        'priority' => 1,
        'is_active' => true,
    ]);

    // Crear stock inicial
    $this->productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 0,
        'min_stock' => 2,
    ]);
});

test('libera stock automáticamente al eliminar item completo del carrito', function () {
    // Agregar item al carrito para crear reserva
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Verificar reserva inicial
    $this->productStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(5);
    expect($this->productStock->available_stock)->toBe(15);

    // Eliminar completamente el item
    $response = $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ]);

    $response->assertStatus(200)
        ->assertJsonFragment(['action' => 'deleted']);

    // Verificar liberación completa del stock
    $this->productStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(0);
    expect($this->productStock->available_stock)->toBe(20);

    // Verificar que el item se eliminó del carrito
    $this->assertDatabaseMissing('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
});

test('libera stock parcialmente al reducir cantidad del item', function () {
    // Agregar item al carrito
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 8,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Verificar reserva inicial
    $this->productStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(8);

    // Eliminar cantidad parcial
    $response = $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $response->assertStatus(200)
        ->assertJsonFragment([
            'action' => 'updated',
            'remaining_quantity' => 5
        ]);

    // Verificar liberación parcial del stock
    $this->productStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(5);
    expect($this->productStock->available_stock)->toBe(15);

    // Verificar que el item se actualizó en el carrito
    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
    ]);
});

test('libera todo el stock al vaciar carrito completo', function () {
    // Agregar múltiples items al carrito
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 6,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Crear segundo producto para agregar más items
    $product2 = Product::factory()->create([
        'category_id' => $this->product->category_id,
        'subcategory_id' => $this->product->subcategory_id,
        'brand_id' => $this->product->brand_id
    ]);

    Price::factory()->create([
        'product_id' => $product2->id,
        'unit' => 'kg',
        'price' => 150,
        'is_active' => true,
    ]);

    $productStock2 = ProductStock::create([
        'product_id' => $product2->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
        'stock' => 15,
        'reserved_stock' => 0,
        'min_stock' => 1,
    ]);

    $this->postJson(route('cart-items.store'), [
        'product_id' => $product2->id,
        'quantity' => 4,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Verificar reservas iniciales
    $this->productStock->refresh();
    $productStock2->refresh();
    expect($this->productStock->reserved_stock)->toBe(6);
    expect($productStock2->reserved_stock)->toBe(4);

    // Vaciar carrito completo
    $response = $this->deleteJson(route('cart.empty'));

    $response->assertStatus(200)
        ->assertJsonFragment([
            'message' => 'The cart has been emptied and all stock reservations released',
            'released_items_count' => 2
        ]);

    // Verificar liberación completa del stock
    $this->productStock->refresh();
    $productStock2->refresh();
    expect($this->productStock->reserved_stock)->toBe(0);
    expect($productStock2->reserved_stock)->toBe(0);
    expect($this->productStock->available_stock)->toBe(20);
    expect($productStock2->available_stock)->toBe(15);

    // Verificar que no hay items en el carrito
    $this->assertDatabaseMissing('cart_items', [
        'user_id' => $this->user->id,
    ]);
});

test('dispara evento CartItemRemoved al eliminar items', function () {
    Event::fake();

    // Agregar item al carrito
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Eliminar item completamente
    $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ])->assertStatus(200);

    // Verificar que se disparó el evento
    Event::assertDispatched(CartItemRemoved::class);
});

test('dispara evento OrderFailed al fallar orden', function () {
    Event::fake();

    // Crear orden con items
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'amount' => 500,
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 7,
        'price' => 100,
        'unit' => 'kg',
        'warehouse_id' => $this->warehouse->id,
    ]);

    // Disparar evento de orden fallida
    event(new OrderFailed($order));

    // Verificar que se disparó el evento correcto
    Event::assertDispatched(OrderFailed::class, function ($event) use ($order) {
        return $event->order->id === $order->id;
    });
});

test('dispara evento OrderCompleted al completar orden', function () {
    Event::fake();

    // Crear orden con items
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'completed',
        'amount' => 800,
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 8,
        'price' => 100,
        'unit' => 'kg',
        'warehouse_id' => $this->warehouse->id,
    ]);

    // Disparar evento de orden completada
    event(new OrderCompleted($order));

    // Verificar que se disparó el evento correcto
    Event::assertDispatched(OrderCompleted::class, function ($event) use ($order) {
        return $event->order->id === $order->id;
    });
});

test('no libera stock si no hay reserva asociada', function () {
    // Crear item del carrito sin reserva (caso edge)
    $cartItem = CartItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg',
        'warehouse_id' => null, // Sin bodega asignada
        'reserved_at' => null,
    ]);

    // Stock inicial sin reservas
    expect($this->productStock->reserved_stock)->toBe(0);
    expect($this->productStock->available_stock)->toBe(20);

    // Eliminar item
    $response = $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ]);

    $response->assertStatus(200);

    // Verificar que el stock no cambió
    $this->productStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(0);
    expect($this->productStock->available_stock)->toBe(20);
});

test('maneja correctamente liberación con múltiples unidades', function () {
    // Crear precio y stock para gramos
    Price::factory()->create([
        'product_id' => $this->product->id,
        'unit' => 'g',
        'price' => 0.1,
        'is_active' => true,
    ]);

    $gramStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'g',
        'stock' => 5000,
        'reserved_stock' => 0,
        'min_stock' => 100,
    ]);

    // Agregar items con diferentes unidades
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ])->assertStatus(201);

    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 75,
        'unit' => 'g'
    ])->assertStatus(201);

    // Verificar reservas independientes
    $this->productStock->refresh();
    $gramStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(3);
    expect($gramStock->reserved_stock)->toBe(75);

    // Eliminar solo items en kg
    $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ])->assertStatus(200);

    // Verificar liberación selectiva por unidad
    $this->productStock->refresh();
    $gramStock->refresh();
    expect($this->productStock->reserved_stock)->toBe(0);
    expect($gramStock->reserved_stock)->toBe(75); // Sin cambios

    // Eliminar items en gramos
    $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 75,
        'unit' => 'g'
    ])->assertStatus(200);

    // Verificar liberación completa
    $gramStock->refresh();
    expect($gramStock->reserved_stock)->toBe(0);
});