<?php

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

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
        'is_active' => true,
        'valid_from' => now()->subDays(1),
        'valid_to' => null
    ]);

    // Crear bodegas con diferentes prioridades
    $this->mainWarehouse = Warehouse::factory()->create([
        'name' => 'Main Warehouse',
        'warehouse_code' => 'MAIN001',
        'priority' => 1,
        'is_active' => true,
    ]);

    $this->secondaryWarehouse = Warehouse::factory()->create([
        'name' => 'Secondary Warehouse',
        'warehouse_code' => 'SEC001',
        'priority' => 2,
        'is_active' => true,
    ]);

    // Crear stock en ambas bodegas
    $this->mainStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 0,
        'min_stock' => 2,
    ]);

    $this->secondaryStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 15,
        'reserved_stock' => 0,
        'min_stock' => 3,
    ]);
});

test('reserva stock automáticamente al agregar item al carrito', function () {
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ];

    $response = $this->postJson(route('cart-items.store'), $data);

    $response->assertStatus(201);

    // Verificar que se creó el item del carrito con reserva
    $cartItem = CartItem::where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($cartItem)->not->toBeNull();
    expect($cartItem->warehouse_id)->toBe($this->mainWarehouse->id);
    expect($cartItem->reserved_at)->not->toBeNull();

    // Verificar que se reservó el stock en la bodega principal (prioridad 1)
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(3);
    expect($this->mainStock->available_stock)->toBe(7);

    // Verificar que la bodega secundaria no fue afectada
    $this->secondaryStock->refresh();
    expect($this->secondaryStock->reserved_stock)->toBe(0);
});

test('utiliza bodega por prioridad para reservar stock', function () {
    // Reducir stock de bodega principal para forzar uso de secundaria
    $this->mainStock->update(['stock' => 2, 'reserved_stock' => 0]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit' => 'kg'
    ];

    $response = $this->postJson(route('cart-items.store'), $data);

    $response->assertStatus(201);

    $cartItem = CartItem::where('user_id', $this->user->id)->first();

    // Debe usar la bodega secundaria porque la principal no tiene suficiente stock
    expect($cartItem->warehouse_id)->toBe($this->secondaryWarehouse->id);

    // Verificar reserva en bodega secundaria
    $this->secondaryStock->refresh();
    expect($this->secondaryStock->reserved_stock)->toBe(5);
    expect($this->secondaryStock->available_stock)->toBe(10);
});

test('falla cuando no hay stock suficiente en ninguna bodega', function () {
    // Reducir stock en ambas bodegas
    $this->mainStock->update(['stock' => 2]);
    $this->secondaryStock->update(['stock' => 3]);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 10,
        'unit' => 'kg'
    ];

    $response = $this->postJson(route('cart-items.store'), $data);

    $response->assertStatus(400)
        ->assertJsonFragment(['available_stock' => 5]);

    // Verificar que no se creó ningún item en el carrito
    $this->assertDatabaseMissing('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
});

test('reserva stock correctamente con múltiples unidades', function () {
    // Crear precio y stock para unidad gramos
    Price::factory()->create([
        'product_id' => $this->product->id,
        'unit' => 'g',
        'price' => 0.1,
        'is_active' => true,
    ]);

    $gramStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'g',
        'stock' => 5000,
        'reserved_stock' => 0,
        'min_stock' => 100,
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

    // Agregar items con diferentes unidades
    $this->postJson(route('cart-items.store'), $dataKg)->assertStatus(201);
    $this->postJson(route('cart-items.store'), $dataG)->assertStatus(201);

    // Verificar reservas independientes por unidad
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(2);

    $gramStock->refresh();
    expect($gramStock->reserved_stock)->toBe(50);

    // Verificar que hay 2 items diferentes en el carrito
    expect(CartItem::where('user_id', $this->user->id)->count())->toBe(2);
});

test('actualiza correctamente reserva cuando se incrementa cantidad existente', function () {
    // Crear item inicial
    $initialData = [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ];

    $this->postJson(route('cart-items.store'), $initialData)->assertStatus(201);

    // Verificar reserva inicial
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(3);

    // Agregar más cantidad del mismo producto
    $additionalData = [
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit' => 'kg'
    ];

    $this->postJson(route('cart-items.store'), $additionalData)->assertStatus(201);

    // Verificar que solo hay un item en el carrito con cantidad total
    $cartItem = CartItem::where('user_id', $this->user->id)->first();
    expect($cartItem->quantity)->toBe(5);

    // Verificar reserva actualizada (el controlador libera 3 y reserva 2)
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(2);
});

test('maneja reservas concurrentes correctamente', function () {
    // Reducir stock total para forzar escasez
    $this->mainStock->update(['stock' => 8]);
    $this->secondaryStock->update(['stock' => 2]);

    // Simular múltiples usuarios agregando el mismo producto simultáneamente
    $user2 = User::factory()->create();
    $user2->givePermissionTo(['create-cart-items']);

    $data = [
        'product_id' => $this->product->id,
        'quantity' => 6,
        'unit' => 'kg'
    ];

    // Usuario 1 agrega al carrito (usa bodega principal)
    $response1 = $this->postJson(route('cart-items.store'), $data);
    $response1->assertStatus(201);

    // Usuario 2 intenta agregar la misma cantidad (debería fallar por stock insuficiente total)
    $response2 = $this->actingAs($user2, 'sanctum')
        ->postJson(route('cart-items.store'), $data);

    $response2->assertStatus(400)
        ->assertJsonFragment(['available_stock' => 4]);

    // Verificar estado de las reservas
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(6);
    expect($this->mainStock->available_stock)->toBe(2);

    // Bodega secundaria no debería tener reservas
    $this->secondaryStock->refresh();
    expect($this->secondaryStock->reserved_stock)->toBe(0);
});

test('reserva se mantiene hasta expiración o eliminación del carrito', function () {
    $data = [
        'product_id' => $this->product->id,
        'quantity' => 4,
        'unit' => 'kg'
    ];

    $this->postJson(route('cart-items.store'), $data)->assertStatus(201);

    $cartItem = CartItem::where('user_id', $this->user->id)->first();
    
    // Verificar que la reserva persiste
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(4);

    // Simular paso del tiempo (reserva aún válida)
    $cartItem->update(['reserved_at' => now()->subMinutes(30)]);
    
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(4);
});

test('puede reservar stock después de liberación parcial', function () {
    // Agregar item inicial
    $this->postJson(route('cart-items.store'), [
        'product_id' => $this->product->id,
        'quantity' => 8,
        'unit' => 'kg'
    ])->assertStatus(201);

    // Verificar reserva inicial
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(8);
    expect($this->mainStock->available_stock)->toBe(2);

    // Eliminar cantidad parcial
    $this->deleteJson(route('cart-items.destroy'), [
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit' => 'kg'
    ])->assertStatus(200);

    // Verificar liberación parcial
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(5);
    expect($this->mainStock->available_stock)->toBe(5);

    // Ahora debe poder agregar más items
    $user2 = User::factory()->create();
    $user2->givePermissionTo(['create-cart-items']);

    $response = $this->actingAs($user2, 'sanctum')
        ->postJson(route('cart-items.store'), [
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit' => 'kg'
        ]);

    $response->assertStatus(201);

    // Verificar nueva reserva
    $this->mainStock->refresh();
    expect($this->mainStock->reserved_stock)->toBe(8);
});