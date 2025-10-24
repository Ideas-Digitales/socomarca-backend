<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Warehouse;

beforeEach(function () {
    // Crear usuario autenticado con permisos
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['create-cart-items', 'read-all-products']);
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
});

test('calcula correctamente stock disponible por unidad', function () {
    // Crear stock en diferentes unidades
    $stockKg = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 5,
        'min_stock' => 2,
    ]);

    $stockG = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'g',
        'stock' => 5000,
        'reserved_stock' => 1000,
        'min_stock' => 100,
    ]);

    // Verificar cálculo de stock disponible
    expect($stockKg->available_stock)->toBe(15);
    expect($stockG->available_stock)->toBe(4000);
});

test('obtiene stock total de producto a través de todas las bodegas', function () {
    // Crear stock en múltiples bodegas
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 15,
        'reserved_stock' => 3,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 8,
        'reserved_stock' => 2,
    ]);

    // Usar el método del modelo Product
    $totalStock = $this->product->getTotalAvailableStock();
    expect($totalStock)->toBe(18); // (15-3) + (8-2) = 12 + 6 = 18
});

test('obtiene stock por bodega de un producto', function () {
    // Crear stock en múltiples bodegas
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 12,
        'reserved_stock' => 2,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 6,
        'reserved_stock' => 1,
    ]);

    // Usar el método del modelo Product
    $stockByWarehouse = $this->product->getStockByWarehouse();

    expect($stockByWarehouse)->toHaveCount(2);
    
    // Verificar estructura general
    $mainWarehouseStock = $stockByWarehouse->firstWhere('warehouse_id', $this->mainWarehouse->id);
    $secondaryWarehouseStock = $stockByWarehouse->firstWhere('warehouse_id', $this->secondaryWarehouse->id);
    
    expect($mainWarehouseStock)->not->toBeNull();
    expect($mainWarehouseStock['warehouse_name'])->toBe($this->mainWarehouse->name);
    expect($mainWarehouseStock['total_stock'])->toBe(10);
    expect($mainWarehouseStock['reserved_stock'])->toBe(2);
    
    expect($secondaryWarehouseStock)->not->toBeNull();
    expect($secondaryWarehouseStock['warehouse_name'])->toBe($this->secondaryWarehouse->name);
    expect($secondaryWarehouseStock['total_stock'])->toBe(5);
    expect($secondaryWarehouseStock['reserved_stock'])->toBe(1);
});

test('scope withStock filtra solo stocks con inventario', function () {
    // Crear productos con y sin stock
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 0,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 0,
        'reserved_stock' => 0,
    ]);

    $stocksWithInventory = ProductStock::withStock()->get();
    
    expect($stocksWithInventory)->toHaveCount(1);
    expect($stocksWithInventory->first()->stock)->toBe(10);
});

test('scope withAvailableStock filtra solo stocks disponibles', function () {
    // Crear stocks con diferentes disponibilidades
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 3,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 5,
        'reserved_stock' => 5,
    ]);

    $availableStocks = ProductStock::withAvailableStock()->get();
    
    expect($availableStocks)->toHaveCount(1);
    expect($availableStocks->first()->available_stock)->toBe(7);
});

test('puede reservar stock cuando hay disponibilidad suficiente', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 5,
    ]);

    // Intentar reservar 8 unidades (disponible: 15)
    $result = $productStock->reserveStock(8);
    
    expect($result)->toBe(true);
    $productStock->refresh();
    expect($productStock->reserved_stock)->toBe(13);
    expect($productStock->available_stock)->toBe(7);
});

test('no puede reservar stock cuando no hay suficiente disponibilidad', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 15,
    ]);

    // Intentar reservar 10 unidades (disponible: 5)
    $result = $productStock->reserveStock(10);
    
    expect($result)->toBe(false);
    $productStock->refresh();
    expect($productStock->reserved_stock)->toBe(15); // Sin cambios
});

test('puede liberar stock reservado correctamente', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 10,
    ]);

    // Liberar 4 unidades
    $productStock->releaseStock(4);
    
    $productStock->refresh();
    expect($productStock->reserved_stock)->toBe(6);
    expect($productStock->available_stock)->toBe(14);
});

test('no puede liberar más stock del que está reservado', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 3,
    ]);

    // Intentar liberar 5 unidades (reservado: 3)
    $productStock->releaseStock(5);
    
    $productStock->refresh();
    expect($productStock->reserved_stock)->toBe(0); // Se reduce a 0, no negativo
    expect($productStock->available_stock)->toBe(20);
});

test('puede reducir stock real y reserva simultáneamente', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 20,
        'reserved_stock' => 8,
    ]);

    // Reducir 5 unidades del stock real
    $result = $productStock->reduceStock(5);
    
    expect($result)->toBe(true);
    $productStock->refresh();
    expect($productStock->stock)->toBe(15);
    expect($productStock->reserved_stock)->toBe(3); // Se reduce automáticamente
    expect($productStock->available_stock)->toBe(12);
});

test('no puede reducir más stock del que existe', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 3,
    ]);

    // Intentar reducir 15 unidades (stock: 10)
    $result = $productStock->reduceStock(15);
    
    expect($result)->toBe(false);
    $productStock->refresh();
    expect($productStock->stock)->toBe(10); // Sin cambios
    expect($productStock->reserved_stock)->toBe(3);
});

test('scopes funcionan correctamente con filtros combinados', function () {
    $product2 = Product::factory()->create([
        'category_id' => $this->product->category_id,
        'subcategory_id' => $this->product->subcategory_id,
        'brand_id' => $this->product->brand_id,
    ]);

    // Crear múltiples stocks para diferentes combinaciones
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 15,
        'reserved_stock' => 5,
    ]);

    ProductStock::create([
        'product_id' => $product2->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'g',
        'stock' => 0,
        'reserved_stock' => 0,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 10,
    ]);

    // Filtrar por producto y bodega con stock disponible
    $filteredStock = ProductStock::byProduct($this->product->id)
        ->byWarehouse($this->mainWarehouse->id)
        ->byUnit('kg')
        ->withAvailableStock()
        ->get();

    expect($filteredStock)->toHaveCount(1);
    expect($filteredStock->first()->available_stock)->toBe(10);
});

test('producto muestra stock correcto en API mediante ProductResource', function () {
    // Crear stock en múltiples bodegas y unidades
    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 12,
        'reserved_stock' => 2,
    ]);

    ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->secondaryWarehouse->id,
        'unit' => 'kg',
        'stock' => 8,
        'reserved_stock' => 3,
    ]);

    // Obtener producto mediante API
    $response = $this->getJson("/api/products/{$this->product->id}");

    $response->assertStatus(200);
    
    // Verificar que la respuesta contiene datos del producto
    $productData = $response->json();
    
    // Si los datos están envueltos en un objeto 'data'
    if (isset($productData['data'])) {
        $productData = $productData['data'];
    }
    
    // Verificar que contiene los campos básicos esperados
    expect($productData)->toHaveKey('id');
    expect($productData)->toHaveKey('name');
    expect($productData)->toHaveKey('stock');
    expect($productData)->toHaveKey('stock_by_warehouse');
    
    // Verificar que el stock total es correcto
    expect($productData['stock'])->toBe(15); // (12-2) + (8-3)
});

test('sistema maneja correctamente sincronización de stock desde ERP', function () {
    // Crear stock inicial
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 100,
        'reserved_stock' => 10,
    ]);

    // Simular actualización desde ERP (nuevo stock: 80)
    $productStock->update(['stock' => 80]);

    // Verificar que las reservas se mantienen correctas
    $productStock->refresh();
    expect($productStock->stock)->toBe(80);
    expect($productStock->reserved_stock)->toBe(10);
    expect($productStock->available_stock)->toBe(70);
});

test('sistema maneja correctamente inconsistencias temporales en reservas', function () {
    $productStock = ProductStock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->mainWarehouse->id,
        'unit' => 'kg',
        'stock' => 50,
        'reserved_stock' => 20,
    ]);

    // Simular reducción drástica de stock desde ERP (ej: 15)
    $productStock->update(['stock' => 15]);
    $productStock->refresh();
    
    // El sistema permite temporalmente available_stock negativo
    // Esto es correcto para manejar sincronizaciones del ERP
    expect($productStock->stock)->toBe(15);
    expect($productStock->reserved_stock)->toBe(20);
    expect($productStock->available_stock)->toBe(-5);
    
    // En un flujo real, el sistema de reservas no permitiría nuevas reservas
    $canReserveMore = $productStock->reserveStock(1);
    expect($canReserveMore)->toBe(false);
});