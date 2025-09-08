<?php

use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;

beforeEach(function () {
    // Crear usuario con permisos de administrador para gestionar bodegas
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['manage-warehouses', 'read-warehouses']);
    
    // Crear usuario regular para pruebas de permisos
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['create-cart-items']);
});

test('puede listar todas las bodegas activas', function () {
    // Crear bodegas de prueba
    $activeWarehouses = Warehouse::factory()->count(3)->create([
        'is_active' => true
    ]);
    
    $inactiveWarehouse = Warehouse::factory()->create([
        'is_active' => false
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson(route('warehouses.index'));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'warehouse_code',
                    'address',
                    'phone',
                    'priority',
                    'is_active',
                ]
            ]
        ])
        ->assertJsonCount(3, 'data');
});

test('puede ver detalles de una bodega específica', function () {
    $warehouse = Warehouse::factory()->create([
        'name' => 'Bodega Central',
        'warehouse_code' => 'CENTRAL001',
        'priority' => 1,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson(route('warehouses.show', $warehouse));

    $response->assertStatus(200)
        ->assertJsonFragment([
            'name' => 'Bodega Central',
            'warehouse_code' => 'CENTRAL001',
            'priority' => 1,
            'is_active' => true,
        ]);
});

test('puede establecer bodega por defecto', function () {
    $warehouse = Warehouse::factory()->create([
        'priority' => 3,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->patchJson(route('warehouses.set-default', $warehouse));

    $response->assertStatus(200)
        ->assertJsonFragment([
            'message' => 'Default warehouse updated successfully'
        ]);

    $warehouse->refresh();
    expect($warehouse->priority)->toBe(1);
});

test('puede obtener resumen de stock por bodega', function () {
    // Crear datos de prueba
    $warehouse = Warehouse::factory()->create(['name' => 'Test Warehouse']);
    
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson(route('warehouses.stock-summary'));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'warehouse_code',
                    'is_active',
                    'priority',
                    'product_stocks',
                ]
            ],
            'message'
        ]);
});


test('puede obtener stock de productos por bodega', function () {
    // Crear datos de prueba
    $warehouse = Warehouse::factory()->create(['name' => 'Test Warehouse']);
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
    $brand = Brand::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'brand_id' => $brand->id
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'unit' => 'kg',
        'stock' => 10,
        'reserved_stock' => 2,
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson(route('warehouses.product-stock', $warehouse));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'message'
        ])
        ->assertJsonPath('message', 'Product stock retrieved successfully');
});



test('requiere autenticación para acceder a bodegas', function () {
    $response = $this->getJson(route('warehouses.index'));
    $response->assertStatus(401);
});

test('requiere permisos para gestionar bodegas', function () {
    // Usuario sin permisos no puede establecer bodega por defecto
    $warehouse = Warehouse::factory()->create();
    
    $response = $this->actingAs($this->user, 'sanctum')
        ->patchJson(route('warehouses.set-default', $warehouse));

    $response->assertStatus(403);
});

test('ordena bodegas por prioridad', function () {
    // Crear bodegas con diferentes prioridades
    $warehouse1 = Warehouse::factory()->create(['priority' => 3, 'name' => 'Tercera']);
    $warehouse2 = Warehouse::factory()->create(['priority' => 1, 'name' => 'Primera']);
    $warehouse3 = Warehouse::factory()->create(['priority' => 2, 'name' => 'Segunda']);

    // Usar el scope byPriority
    $warehouses = Warehouse::byPriority()->get();

    expect($warehouses->first()->name)->toBe('Primera');
    expect($warehouses->get(1)->name)->toBe('Segunda');
    expect($warehouses->get(2)->name)->toBe('Tercera');
});

test('puede obtener bodega por defecto', function () {
    // Crear varias bodegas
    Warehouse::factory()->create(['priority' => 3]);
    $defaultWarehouse = Warehouse::factory()->create([
        'priority' => 1,
        'is_active' => true,
        'name' => 'Default Warehouse'
    ]);
    Warehouse::factory()->create(['priority' => 2]);

    // Usar el scope default
    $warehouse = Warehouse::default()->first();

    expect($warehouse->name)->toBe('Default Warehouse');
    expect($warehouse->isDefault())->toBe(true);
});

test('puede filtrar bodegas activas', function () {
    Warehouse::factory()->count(2)->create(['is_active' => true]);
    Warehouse::factory()->count(3)->create(['is_active' => false]);

    $activeWarehouses = Warehouse::active()->get();

    expect($activeWarehouses->count())->toBe(2);
    $activeWarehouses->each(function ($warehouse) {
        expect($warehouse->is_active)->toBe(true);
    });
});

test('puede obtener stock por bodega para un producto', function () {
    // Crear datos de prueba
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
    $brand = Brand::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'brand_id' => $brand->id
    ]);

    $warehouse1 = Warehouse::factory()->create(['name' => 'Bodega 1']);
    $warehouse2 = Warehouse::factory()->create(['name' => 'Bodega 2']);

    // Crear stock en ambas bodegas
    ProductStock::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse1->id,
        'unit' => 'kg',
        'stock' => 15,
        'reserved_stock' => 5,
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse2->id,
        'unit' => 'kg',
        'stock' => 8,
        'reserved_stock' => 2,
    ]);

    // Obtener stock por bodega
    $stockByWarehouse = ProductStock::where('product_id', $product->id)
        ->with('warehouse')
        ->get()
        ->keyBy('warehouse.name');

    expect($stockByWarehouse)->toHaveCount(2);
    expect($stockByWarehouse['Bodega 1']->available_stock)->toBe(10);
    expect($stockByWarehouse['Bodega 2']->available_stock)->toBe(6);
});

test('puede sincronizar bodegas desde Random ERP', function () {
    // Test que simula la sincronización desde Random API
    $warehouseData = [
        'business_code' => '01',
        'branch_code' => 'ERP',
        'warehouse_code' => 'ERP001',
        'name' => 'Bodega desde ERP',
        'address' => 'Dirección ERP',
        'phone' => '+56999888777',
        'priority' => 1,
        'is_active' => true,
        'no_explosion' => false,
        'no_lot' => true,
        'no_location' => false,
        'warehouse_type' => 'general',
    ];

    // Simular creación/actualización usando firstOrCreate
    $warehouse = Warehouse::firstOrCreate(
        ['warehouse_code' => $warehouseData['warehouse_code']],
        $warehouseData
    );

    expect($warehouse->name)->toBe('Bodega desde ERP');
    expect($warehouse->no_lot)->toBe(true);
    expect($warehouse->warehouse_type)->toBe('general');

    // Verificar que existe en la base de datos
    $this->assertDatabaseHas('warehouses', [
        'warehouse_code' => 'ERP001',
        'name' => 'Bodega desde ERP',
        'no_lot' => true,
    ]);
});