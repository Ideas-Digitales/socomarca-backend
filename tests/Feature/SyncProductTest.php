<?php

use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomStock;
use App\Console\Commands\SyncRandomProductsCommand;
use App\Services\RandomApiService;
use App\Models\Product;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Price;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Limpiar las tablas antes de cada test
    Product::truncate();
    Category::truncate();
    Subcategory::truncate();
    Price::truncate();
});

describe('Product Sync Basic', function () {

    test('el comando de sincronización encola el job correctamente', function () {
        Queue::fake();

        Artisan::call('random:sync-products');

        Queue::assertPushed(SyncRandomProducts::class);
    });

    test('el job de sincronización procesa productos correctamente', function () {
        // Mock del servicio RandomApiService
        $mockApiService = Mockery::mock(RandomApiService::class);
        
        // Crear categorías de prueba
        $category = Category::create([
            'name' => 'Categoría Test',
            'code' => 'CAT001',
            'level' => 1,
            'key' => 'CAT1'
        ]);

        $subcategory = Subcategory::create([
            'name' => 'Subcategoría Test',
            'code' => 'SUBCAT001',
            'level' => 2,
            'key' => 'CAT1/SUB1',
            'category_id' => $category->id
        ]);

        // Datos de prueba simulando respuesta de la API
        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Test 1',
                    'FMPR' => 'CAT001',
                    'PFPR' => 'SUBCAT001'
                ],
                [
                    'KOPR' => 'PROD002',
                    'NOKOPR' => 'Producto Test 2',
                    'FMPR' => 'CAT001',
                    'PFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        // Ejecutar el job
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        // Verificar que los productos se crearon correctamente
        expect(Product::count())->toBe(2);

        $product1 = Product::where('random_product_id', 'PROD001')->first();
        expect($product1)->not->toBeNull();
        expect($product1->name)->toBe('Producto Test 1');
        expect($product1->sku)->toBe('PROD001');
        expect($product1->category_id)->toBe($category->id);
        expect($product1->subcategory_id)->toBe($subcategory->id);
        expect($product1->status)->toBe(true);

        $product2 = Product::where('random_product_id', 'PROD002')->first();
        expect($product2)->not->toBeNull();
        expect($product2->name)->toBe('Producto Test 2');
        expect($product2->category_id)->toBe($category->id);
        expect($product2->subcategory_id)->toBeNull();
    });

    test('el job actualiza productos existentes en lugar de duplicarlos', function () {
        // Crear un producto existente
        $existingProduct = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Viejo',
            'status' => false
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Actualizado',
                    'FMPR' => '',
                    'PFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        // Ejecutar el job
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        // Verificar que solo hay un producto y se actualizó
        expect(Product::count())->toBe(1);
        
        $updatedProduct = Product::where('random_product_id', 'PROD001')->first();
        expect($updatedProduct->id)->toBe($existingProduct->id);
        expect($updatedProduct->name)->toBe('Producto Actualizado');
        expect($updatedProduct->status)->toBe(true);
    });

    test('el job maneja errores correctamente', function () {
        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andThrow(new Exception('Error de API'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // Ejecutar el job y esperar que lance excepción
        $job = new SyncRandomProducts();
        
        expect(fn() => $job->handle($mockApiService))
            ->toThrow(Exception::class, 'Error de API');
    });

    test('la sincronización de precios funciona correctamente', function () {
        // Crear un producto existente
        $product = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Test',
            'status' => true
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $pricesResponse = [
            'nombre' => 'LISTA_001',
            'datos' => [
                [
                    'kopr' => 'PROD001',
                    'venderen' => 0,
                    'unidades' => [
                        [
                            'nombre' => 'UN',
                            'prunneto' => [
                                ['f' => 1500]
                            ]
                        ],
                        [
                            'nombre' => 'KG',
                            'prunneto' => [
                                ['f' => 2000]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $mockApiService->shouldReceive('getPricesLists')
            ->once()
            ->andReturn($pricesResponse);

        Log::shouldReceive('info')->twice();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Ejecutar el job de precios
        $job = new SyncRandomPrices();
        $job->handle($mockApiService);

        // Verificar que los precios se crearon
        expect(Price::count())->toBe(2);

        $priceUN = Price::where('random_product_id', 'PROD001')
            ->where('unit', 'UN')
            ->first();
        expect($priceUN)->not->toBeNull();
        expect((float)$priceUN->price)->toBe(1500.0);
        expect($priceUN->product_id)->toBe($product->id);
        expect($priceUN->is_active)->toBe(true);

        $priceKG = Price::where('random_product_id', 'PROD001')
            ->where('unit', 'KG')
            ->first();
        expect($priceKG)->not->toBeNull();
        expect((float)$priceKG->price)->toBe(2000.0);
    });

    test('la sincronización de stock actualiza precios existentes', function () {
        // Crear bodega necesaria
        $warehouse = \App\Models\Warehouse::create([
            'business_code' => '01',
            'branch_code' => 'MAIN',
            'warehouse_code' => 'MAIN001',
            'name' => 'Bodega Principal',
            'address' => 'Dirección Principal',
            'priority' => 1,
            'is_active' => true,
        ]);
        
        // Crear producto y precio
        $product = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Test',
            'status' => true
        ]);

        $price = Price::create([
            'product_id' => $product->id,
            'random_product_id' => 'PROD001',
            'price_list_id' => 'LISTA_001',
            'unit' => 'UN',
            'price' => 1500,
            'is_active' => true
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $stockResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'KOBO' => 'MAIN001', // Código de bodega
                    'STOCNV1' => 50,
                    'STVEN1' => 50
                ]
            ]
        ];

        $mockApiService->shouldReceive('getStock')
            ->once()
            ->andReturn($stockResponse);

        Log::shouldReceive('info')->atLeast()->times(1);
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Ejecutar el job de stock
        $job = new SyncRandomStock();
        $job->handle($mockApiService);

        // Verificar que el stock se actualizó en el nuevo sistema ProductStock
        $productStock = \App\Models\ProductStock::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        
        expect($productStock)->not->toBeNull();
        expect($productStock->stock)->toBe(50);
    });

    test('el servicio RandomApiService obtiene token correctamente', function () {
        Http::fake([
            'http://seguimiento.random.cl:3003/login' => Http::response([
                'token' => 'fake-jwt-token'
            ], 200),
            'http://seguimiento.random.cl:3003/productos*' => Http::response([
                'data' => []
            ], 200)
        ]);

        $service = new RandomApiService();
        $result = $service->getProducts();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('data');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://seguimiento.random.cl:3003/login' &&
                   $request['username'] === 'demo@random.cl' &&
                   $request['password'] === 'd3m0r4nd0m3RP';
        });
    });

    test('el servicio maneja tokens expirados correctamente', function () {
        Http::fake([
            'http://seguimiento.random.cl:3003/login' => Http::response([
                'token' => 'fake-jwt-token'
            ], 200),
            'http://seguimiento.random.cl:3003/productos*' => Http::sequence()
                ->push(['message' => 'jwt expired'], 401)
                ->push(['data' => []], 200)
        ]);

        $service = new RandomApiService();
        $result = $service->getProducts();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('data');

        // Debería haber hecho login dos veces (inicial + renovación)
        Http::assertSentCount(4); // 2 login + 2 productos (initial + retry)
    });

    test('la sincronización completa funciona en cadena', function () {
        Queue::fake();

        Artisan::call('random:sync-all');

        // Verificar que se encoló el primer job de la cadena (SyncRandomWarehouses)
        Queue::assertPushed(\App\Jobs\SyncRandomWarehouses::class);
    });

    test('los productos sincronizados tienen la estructura correcta', function () {
        $category = Category::create([
            'name' => 'Categoría Test',
            'code' => 'CAT001',
            'level' => 1,
            'key' => 'CAT1'
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Test',
                    'FMPR' => 'CAT001',
                    'PFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        // Ejecutar sincronización
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        $product = Product::first();

        // Verificar estructura del producto
        expect($product)->toHaveKeys([
            'id',
            'random_product_id',
            'name',
            'sku',
            'category_id',
            'subcategory_id',
            'brand_id',
            'status',
            'created_at',
            'updated_at'
        ]);

        expect($product->random_product_id)->toBe('PROD001');
        expect($product->name)->toBe('Producto Test');
        expect($product->sku)->toBe('PROD001');
        expect($product->status)->toBe(true);
        expect($product->category_id)->toBe($category->id);
    });

    test('la sincronización maneja productos sin categorías', function () {
        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Sin Categoría',
                    'FMPR' => '',
                    'PFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        $product = Product::first();
        expect($product)->not->toBeNull();
        expect($product->category_id)->toBeNull();
        expect($product->subcategory_id)->toBeNull();
        expect($product->name)->toBe('Producto Sin Categoría');
    });

});