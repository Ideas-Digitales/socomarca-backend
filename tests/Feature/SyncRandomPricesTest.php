<?php

use App\Jobs\SyncRandomPrices;
use App\Services\RandomApiService;
use App\Models\User;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

uses(RefreshDatabase::class);

test('command dispatches job to queue', function () {
    $this->artisan('random:sync-prices')
        ->expectsOutput('Iniciando sincronización de precios...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);
});

test('job stops when no price list codes found in users', function () {
    User::factory()->create(['prices_lists' => null]);
    User::factory()->create(['prices_lists' => []]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldNotReceive('getPricesLists');
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->with('SyncRandomPrices started')->once();
    Log::shouldReceive('alert')->with('No price list codes found in users')->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));
});

test('job creates prices for products from single price list', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        [
                            'nombre' => 'kg',
                            'prunneto' => [['f' => 999.50]]
                        ]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'product_id' => $product->id,
        'random_product_id' => '123',
        'price_list_id' => 'Lista 1',
        'unit' => 'kg',
        'price' => 999.50,
        'is_active' => true,
    ]);
});

test('job handles pagination across multiple pages', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product1 = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $product2 = Product::create([
        'code' => 'PROD2',
        'sku' => 'SKU2',
        'name' => 'Producto 2',
        'price' => 2000,
        'random_product_id' => '456',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '456',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'unidad', 'prunneto' => [['f' => 1999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 3)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'random_product_id' => '123',
        'price' => 999,
    ]);
    $this->assertDatabaseHas('prices', [
        'random_product_id' => '456',
        'price' => 1999,
    ]);
    $this->assertDatabaseCount('prices', 2);
});

test('job processes multiple price lists from different users', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);
    User::factory()->create(['prices_lists' => ['LIST2']]);
    User::factory()->create(['prices_lists' => ['LIST1', 'LIST2']]);

    $product1 = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $product2 = Product::create([
        'code' => 'PROD2',
        'sku' => 'SKU2',
        'name' => 'Producto 2',
        'price' => 2000,
        'random_product_id' => '456',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST2', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 2',
            'datos' => [
                [
                    'kopr' => '456',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'unidad', 'prunneto' => [['f' => 1999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST2', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'price_list_id' => 'Lista 1',
        'random_product_id' => '123',
    ]);
    $this->assertDatabaseHas('prices', [
        'price_list_id' => 'Lista 2',
        'random_product_id' => '456',
    ]);
    $this->assertDatabaseCount('prices', 2);
});

test('job skips products that do not exist in database', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]]
                    ]
                ],
                [
                    'kopr' => '999',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 500]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')
        ->with('Product with random_product_id 999 not found')
        ->once();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', ['random_product_id' => '123']);
    $this->assertDatabaseMissing('prices', ['random_product_id' => '999']);
    $this->assertDatabaseCount('prices', 1);
});

test('job handles venderen=1 and only processes first unit', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]],
                        ['nombre' => 'gramo', 'prunneto' => [['f' => 10]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', ['unit' => 'kg', 'price' => 999]);
    $this->assertDatabaseMissing('prices', ['unit' => 'gramo']);
    $this->assertDatabaseCount('prices', 1);
});

test('job handles venderen=2 and only processes second unit', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 2,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]],
                        ['nombre' => 'gramo', 'prunneto' => [['f' => 10]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseMissing('prices', ['unit' => 'kg']);
    $this->assertDatabaseHas('prices', ['unit' => 'gramo', 'price' => 10]);
    $this->assertDatabaseCount('prices', 1);
});

test('job handles venderen=0 and processes both units', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 0,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]],
                        ['nombre' => 'gramo', 'prunneto' => [['f' => 10]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', ['unit' => 'kg', 'price' => 999]);
    $this->assertDatabaseHas('prices', ['unit' => 'gramo', 'price' => 10]);
    $this->assertDatabaseCount('prices', 2);
});

test('job updates existing prices when syncing again', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    Price::create([
        'product_id' => $product->id,
        'random_product_id' => '123',
        'price_list_id' => 'Lista 1',
        'unit' => 'kg',
        'price' => 500,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'random_product_id' => '123',
        'price' => 999,
    ]);
    $this->assertDatabaseCount('prices', 1);
});

test('job handles missing datos key in API response', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn(['nombre' => 'Lista 1']);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseCount('prices', 0);
});

test('job handles API exception and rethrows it', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andThrow(new Exception('API connection failed'));
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')
        ->with('Error sincronizando precios: API connection failed')
        ->once();

    $job = new SyncRandomPrices();

    expect(fn() => $job->handle(app(RandomApiService::class)))
        ->toThrow(Exception::class, 'API connection failed');
});

test('job uses price list code as fallback when nombre is missing', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'kg', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'random_product_id' => '123',
        'price_list_id' => 'LIST1',
    ]);
});

test('job handles empty units array in price data', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'Lista 1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => []
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseCount('prices', 0);
});
