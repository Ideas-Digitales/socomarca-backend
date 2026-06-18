<?php

use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomStock;
use App\Jobs\SyncRandomCategories;
use App\Services\RandomApiService;
use App\Models\Product;
use App\Models\Category;
use App\Models\Price;
use App\Models\Brand;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('prices')->truncate();
    DB::table('products')->truncate();
    Category::where('level', 1)->delete();
    Category::where('level', 2)->delete();
    Category::where('level', 3)->delete();
    DB::table('brands')->truncate();
});

describe('Product Sync Integration', function () {

    test('flujo completo de sincronización funciona correctamente', function () {
        $categoriesResponse = [
            'data' => [
                [
                    'CODIGO' => 'CAT001',
                    'NOMBRE' => 'Alimentación',
                    'NIVEL' => 1,
                    'LLAVE' => 'CAT001'
                ],
                [
                    'CODIGO' => 'SUBCAT001',
                    'NOMBRE' => 'Lácteos',
                    'NIVEL' => 2,
                    'LLAVE' => 'CAT001/SUBCAT001'
                ]
            ]
        ];

        $mockApiService = Mockery::mock(RandomApiService::class);
        $mockApiService->shouldReceive('getCategories')
            ->once()
            ->andReturn($categoriesResponse);

        Log::shouldReceive('info')->atLeast()->times(1);
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $categoriesJob = new SyncRandomCategories();
        $categoriesJob->handle($mockApiService);

        expect(Category::where('level', 1)->count())->toBe(1);
        expect(Category::where('level', 2)->count())->toBe(1);

        $supercategory = Category::where('code', 'CAT001')->where('level', 1)->first();
        $category = Category::where('code', 'SUBCAT001')->where('level', 2)->first();

        $productsResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Leche Descremada 1L',
                    'FMPR' => 'CAT001',
                    'PFPR' => 'SUBCAT001',
                    'HFPR' => ''
                ],
                [
                    'KOPR' => 'PROD002',
                    'NOKOPR' => 'Yogurt Natural 150g',
                    'FMPR' => 'CAT001',
                    'PFPR' => 'SUBCAT001',
                    'HFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($productsResponse);

        $productsJob = new SyncRandomProducts();
        $productsJob->handle($mockApiService);

        expect(Product::count())->toBe(2);

        $product1 = Product::where('random_product_id', 'PROD001')->first();
        expect($product1->supercategory_id)->toBe($supercategory->id);
        expect($product1->category_id)->toBe($category->id);

        // 3. Sincronizar precios
        $pricesResponse = [
            'nombre' => 'LISTA_PUBLICA',
            'datos' => [
                [
                    'kopr' => 'PROD001',
                    'venderen' => 1,
                    'unidades' => [
                        [
                            'nombre' => 'UN',
                            'prunneto' => [['f' => 1200]]
                        ]
                    ]
                ],
                [
                    'kopr' => 'PROD002',
                    'venderen' => 1,
                    'unidades' => [
                        [
                            'nombre' => 'UN',
                            'prunneto' => [['f' => 800]]
                        ]
                    ]
                ]
            ]
        ];

        $mockApiService->shouldReceive('getPricesLists')
            ->once()
            ->andReturn($pricesResponse);

        $pricesJob = new SyncRandomPrices();
        $pricesJob->handle($mockApiService);

        // Verificar precios creados
        expect(Price::count())->toBe(2);

        // 4. Sincronizar stock
        $stockResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'STOCNV1' => 45,
                    'STVEN1' => 45
                ],
                [
                    'KOPR' => 'PROD002',
                    'STOCNV1' => 23,
                    'STVEN1' => 23
                ]
            ]
        ];

        $mockApiService->shouldReceive('getStock')
            ->once()
            ->andReturn($stockResponse);

        $stockJob = new SyncRandomStock();
        $stockJob->handle($mockApiService);

        // Verificar stock actualizado
        $price1 = Price::where('random_product_id', 'PROD001')->first();
        $price2 = Price::where('random_product_id', 'PROD002')->first();

        expect($price1->stock)->toBe(45);
        expect($price2->stock)->toBe(23);

        // Verificar integridad de datos completa
        $products = Product::with(['supercategory', 'category', 'prices'])->get();
        
        foreach ($products as $product) {
            expect($product->supercategory)->not->toBeNull();
            expect($product->category)->not->toBeNull();
            expect($product->prices)->not->toBeEmpty();
            expect($product->prices->first()->stock)->toBeGreaterThan(0);
        }
    });

    test('manejo de errores en cadena de sincronización', function () {
        // Simular error en sincronización de productos
        $mockApiService = Mockery::mock(RandomApiService::class);
        
        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andThrow(new Exception('API timeout'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        $job = new SyncRandomProducts();
        
        expect(fn() => $job->handle($mockApiService))
            ->toThrow(Exception::class, 'API timeout');

        // Verificar que no se crearon datos inconsistentes
        expect(Product::count())->toBe(0);
    });

    test('sincronización con gran volumen de datos', function () {
        // Simular respuesta con muchos productos
        $products = [];
        for ($i = 1; $i <= 100; $i++) {
            $products[] = [
                'KOPR' => "PROD" . str_pad($i, 3, '0', STR_PAD_LEFT),
                'NOKOPR' => "Producto Test $i",
                'FMPR' => '',
                'PFPR' => '',
                'HFPR' => ''
            ];
        }

        $productsResponse = ['data' => $products];

        $mockApiService = Mockery::mock(RandomApiService::class);
        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($productsResponse);

        Log::shouldReceive('info')->twice();

        // Medir tiempo de ejecución
        $startTime = microtime(true);
        
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);
        
        $executionTime = microtime(true) - $startTime;

        // Verificar que se procesaron todos los productos
        expect(Product::count())->toBe(100);
        
        // Verificar que el tiempo de ejecución es razonable (menos de 5 segundos)
        expect($executionTime)->toBeLessThan(5.0);

        // Verificar que los productos están correctamente guardados
        expect(Product::where('random_product_id', 'PROD001')->exists())->toBe(true);
        expect(Product::where('random_product_id', 'PROD100')->exists())->toBe(true);
    });

    test('sincronización preserva relaciones existentes', function () {
        $supercategory = Category::create([
            'name' => 'Super Categoría Existente',
            'code' => 'CAT001',
            'level' => 1,
            'key' => 'CAT1'
        ]);

        $brand = Brand::create([
            'name' => 'Marca Test'
        ]);

        $product = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Original',
            'supercategory_id' => $supercategory->id,
            'brand_id' => $brand->id,
            'status' => true
        ]);

        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Actualizado',
                    'FMPR' => 'CAT001',
                    'PFPR' => '',
                    'HFPR' => ''
                ]
            ]
        ];

        $mockApiService = Mockery::mock(RandomApiService::class);
        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        $updatedProduct = Product::where('random_product_id', 'PROD001')->first();
        expect($updatedProduct->id)->toBe($product->id);
        expect($updatedProduct->name)->toBe('Producto Actualizado');
        expect($updatedProduct->supercategory_id)->toBe($supercategory->id);
        expect($updatedProduct->brand_id)->toBeNull();
    });

    test('verificación de consistencia de datos después de sincronización', function () {
        $supercategory = Category::create([
            'name' => 'Alimentación',
            'code' => 'CAT001',
            'level' => 1,
            'key' => 'CAT1'
        ]);

        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Test',
                    'FMPR' => 'CAT001',
                    'PFPR' => '',
                    'HFPR' => ''
                ]
            ]
        ];

        $mockApiService = Mockery::mock(RandomApiService::class);
        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        $product = Product::first();

        expect(Product::where('random_product_id', $product->random_product_id)->count())->toBe(1);

        expect($product->sku)->toBe($product->random_product_id);

        if ($product->supercategory_id) {
            expect(Category::find($product->supercategory_id))->not->toBeNull();
        }

        if ($product->category_id) {
            expect(Category::find($product->category_id))->not->toBeNull();
        }

        if ($product->subcategory_id) {
            expect(Category::find($product->subcategory_id))->not->toBeNull();
        }

        expect($product->name)->not->toBeEmpty();
        expect($product->random_product_id)->not->toBeEmpty();
        expect($product->sku)->not->toBeEmpty();
        expect($product->status)->toBe(true);
    });

});