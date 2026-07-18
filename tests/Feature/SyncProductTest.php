<?php

use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomStock;
use App\Services\RandomApiService;
use App\Models\Product;
use App\Models\Category;
use App\Models\Price;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Product::truncate();
    Category::where('level', 1)->delete();
    Category::where('level', 2)->delete();
    Category::where('level', 3)->delete();
    Price::truncate();
});

describe('Product Sync Basic', function () {

    it('queues the job when the sync command runs', function () {
        Queue::fake();

        Artisan::call('random:sync-products');

        Queue::assertPushed(SyncRandomProducts::class);
    });

    it('processes products correctly', function () {
        $mockApiService = Mockery::mock(RandomApiService::class);

        $supercategory = Category::create([
            'name' => 'Super Categoría Test',
            'code' => 'CAT001',
            'level' => 1,
            'key' => 'CAT1'
        ]);

        $category = Category::create([
            'name' => 'Categoría Test',
            'code' => 'SUBCAT001',
            'level' => 2,
            'key' => 'CAT1/SUB1',
            'parent_category_id' => $supercategory->id
        ]);

        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Test 1',
                    'FMPR' => 'CAT001',
                    'PFPR' => 'SUBCAT001',
                    'HFPR' => ''
                ],
                [
                    'KOPR' => 'PROD002',
                    'NOKOPR' => 'Producto Test 2',
                    'FMPR' => 'CAT001',
                    'PFPR' => '',
                    'HFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        expect(Product::count())->toBe(2);

        $product1 = Product::where('random_product_id', 'PROD001')->first();
        expect($product1)->not->toBeNull();
        expect($product1->name)->toBe('Producto Test 1');
        expect($product1->sku)->toBe('PROD001');
        expect($product1->supercategory_id)->toBe($supercategory->id);
        expect($product1->category_id)->toBe($category->id);
        expect($product1->subcategory_id)->toBeNull();
        expect($product1->status)->toBe(true);

        $product2 = Product::where('random_product_id', 'PROD002')->first();
        expect($product2)->not->toBeNull();
        expect($product2->name)->toBe('Producto Test 2');
        expect($product2->supercategory_id)->toBe($supercategory->id);
        expect($product2->category_id)->toBeNull();
        expect($product2->subcategory_id)->toBeNull();
    });

    it('updates existing products instead of duplicating them', function () {
        // Create an existing product
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

        // Run the job
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        // Verify only one product exists and it was updated
        expect(Product::count())->toBe(1);

        $updatedProduct = Product::where('random_product_id', 'PROD001')->first();
        expect($updatedProduct->id)->toBe($existingProduct->id);
        expect($updatedProduct->name)->toBe('Producto Actualizado');
        expect($updatedProduct->status)->toBe(true);
    });

    it('handles errors correctly', function () {
        $mockApiService = Mockery::mock(RandomApiService::class);

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andThrow(new Exception('Error de API'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // Run the job and expect it to throw
        $job = new SyncRandomProducts();

        expect(fn() => $job->handle($mockApiService))
            ->toThrow(Exception::class, 'Error de API');
    });

    it('synchronizes prices correctly', function () {
        // Create an existing product
        $product = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Test',
            'status' => true
        ]);

        $priceList = getPriceListCode();

        User::factory()->create([
            'prices_lists' => [$priceList],
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);

        $pricesResponse = [
            'nombre' => $priceList,
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
            ->with($priceList, 100, 1)
            ->andReturn($pricesResponse);

        $mockApiService->shouldReceive('getPricesLists')
            ->once()
            ->with($priceList, 100, 2)
            ->andReturn(['nombre' => $priceList, 'datos' => []]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('alert')->zeroOrMoreTimes();

        // Run the prices job
        $job = new SyncRandomPrices();
        $job->handle($mockApiService);

        // Verify the prices were created
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

    it('updates existing prices when syncing stock', function () {
        // Create a product and price
        $product = Product::create([
            'random_product_id' => 'PROD001',
            'sku' => 'PROD001',
            'name' => 'Producto Test',
            'status' => true
        ]);
        $priceList = getPriceListCode();
        $price = Price::create([
            'product_id' => $product->id,
            'random_product_id' => 'PROD001',
            'price_list_id' => $priceList,
            'unit' => 'UN',
            'price' => 1500,
            'is_active' => true,
            'stock' => 0
        ]);

        $mockApiService = Mockery::mock(RandomApiService::class);

        $stockResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'STOCNV1' => 50,
                    'STVEN1' => 50
                ]
            ]
        ];

        $mockApiService->shouldReceive('getStock')
            ->once()
            ->andReturn($stockResponse);

        Log::shouldReceive('info')->twice();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Run the stock job
        $job = new SyncRandomStock();
        $job->handle($mockApiService);

        // Verify the stock was updated
        $price->refresh();
        expect($price->stock)->toBe(50);
    });

    it('authenticates RandomApiService using the configured token and fetches products', function () {
        $baseUrl = config('random.url');
        $token = config('random.token');

        Http::fake([
            $baseUrl . '/productos*' => Http::response([
                'data' => []
            ], 200)
        ]);

        $service = new RandomApiService();
        $result = $service->getProducts();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('data');

        // makeRequest() always authenticates with the pre-configured Random token
        // (config('random.token')) rather than logging in for every request.
        Http::assertSent(function ($request) use ($baseUrl, $token) {
            return str_starts_with($request->url(), $baseUrl . '/productos') &&
                $request->hasHeader('Authorization', 'Bearer ' . $token);
        });
    });

    it('handles expired tokens correctly', function () {
        $baseUrl = config('random.url');
        Http::fake([
            $baseUrl . '/login' => Http::response([
                'token' => 'fake-jwt-token'
            ], 200),
            $baseUrl . '/productos*' => Http::sequence()
                ->push(['message' => 'jwt expired'], 401)
                ->push(['data' => []], 200)
        ]);

        $service = new RandomApiService();
        $result = $service->getProducts();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('data');

        // The first request uses the pre-configured token and gets "jwt expired";
        // only then does the service log in once to retry with a fresh token.
        Http::assertSentCount(3); // 1 initial productos + 1 login + 1 retried productos
    });

    it('runs the full sync as a chain', function () {
        Queue::fake();

        Artisan::call('random:sync-all');

        // Verify at least one job was queued (the chain counts as one push)
        Queue::assertPushed(\App\Jobs\SyncRandomCategories::class);
    });

    it('synced products have the correct structure', function () {
        $supercategory = Category::create([
            'name' => 'Super Categoría Test',
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
                    'PFPR' => '',
                    'HFPR' => ''
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

        expect($product)->toHaveKeys([
            'id',
            'random_product_id',
            'name',
            'sku',
            'supercategory_id',
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
        expect($product->supercategory_id)->toBe($supercategory->id);
    });

    it('handles products without categories', function () {
        $mockApiService = Mockery::mock(RandomApiService::class);

        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD001',
                    'NOKOPR' => 'Producto Sin Categoría',
                    'FMPR' => '',
                    'PFPR' => '',
                    'HFPR' => ''
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
        expect($product->supercategory_id)->toBeNull();
        expect($product->category_id)->toBeNull();
        expect($product->subcategory_id)->toBeNull();
        expect($product->name)->toBe('Producto Sin Categoría');
    });

    it('deactivates products that no longer come from the API', function () {
        // 1. Create two initial active products
        Product::create([
            'random_product_id' => 'PROD_STAY',
            'sku' => 'PROD_STAY',
            'name' => 'Producto que sigue en API',
            'status' => true
        ]);

        Product::create([
            'random_product_id' => 'PROD_GONE',
            'sku' => 'PROD_GONE',
            'name' => 'Producto que desaparece',
            'status' => true
        ]);

        // 2. Simulate the API returning only the first one
        $mockApiService = Mockery::mock(RandomApiService::class);
        $apiResponse = [
            'data' => [
                [
                    'KOPR' => 'PROD_STAY',
                    'NOKOPR' => 'Producto que sigue en API',
                    'FMPR' => '',
                    'PFPR' => '',
                    'HFPR' => ''
                ]
            ]
        ];

        $mockApiService->shouldReceive('getProducts')
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->twice();

        // 3. Run the job
        $job = new SyncRandomProducts();
        $job->handle($mockApiService);

        // 4. Verify results
        expect(Product::where('random_product_id', 'PROD_STAY')->first()->status)->toBe(true);
        expect(Product::where('random_product_id', 'PROD_GONE')->first()->status)->toBe(false);
    });
});
