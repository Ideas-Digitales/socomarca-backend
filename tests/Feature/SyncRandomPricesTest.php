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

test('job syncs only the venderen unit when both units share the same name', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    Product::create([
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
                        ['nombre' => 'UN', 'prunneto' => [['f' => 999]]],
                        ['nombre' => 'UN', 'prunneto' => [['f' => 0]]]
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

    $this->assertDatabaseHas('prices', ['unit' => 'UN', 'price' => 999]);
    $this->assertDatabaseCount('prices', 1);
});

test('job falls back to the first unit when the venderen unit is missing', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    Product::create([
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
    Log::shouldReceive('warning')
        ->with('Unit 2 not found for random_product_id 123 in price list LIST1, using the first unit')
        ->once();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', ['unit' => 'kg', 'price' => 999]);
    $this->assertDatabaseCount('prices', 1);
});

test('job uses the first unit when venderen is missing', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    Product::create([
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
    $this->assertDatabaseCount('prices', 1);
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
    Log::shouldReceive('warning')
        ->with('Price list LIST1 sync stopped at page 1: no datos in response')
        ->once();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseCount('prices', 0);
});

test('job deactivates the prices of the list that Random no longer returns', function () {
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

    // The sale unit of product 123 changed from UN to KG and product 456 left the list.
    $unitChanged = Price::create([
        'product_id' => $product1->id,
        'random_product_id' => '123',
        'price_list_id' => 'LIST1',
        'unit' => 'UN',
        'price' => 500,
        'is_active' => true,
    ]);
    $removed = Price::create([
        'product_id' => $product2->id,
        'random_product_id' => '456',
        'price_list_id' => 'LIST1',
        'unit' => 'UN',
        'price' => 700,
        'is_active' => true,
    ]);
    $otherList = Price::create([
        'product_id' => $product2->id,
        'random_product_id' => '456',
        'price_list_id' => 'LIST2',
        'unit' => 'UN',
        'price' => 800,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'LIST1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'KG', 'prunneto' => [['f' => 999]]],
                        ['nombre' => 'KG', 'prunneto' => [['f' => 0]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['nombre' => 'LIST1', 'datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', [
        'random_product_id' => '123',
        'price_list_id' => 'LIST1',
        'unit' => 'KG',
        'price' => 999,
        'is_active' => true,
    ]);
    expect($unitChanged->fresh()->is_active)->toBeFalse();
    expect($removed->fresh()->is_active)->toBeFalse();
    expect($otherList->fresh()->is_active)->toBeTrue();
});

test('job keeps the prices of the list when the sync stops with an error response', function () {
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

    $notReached = Price::create([
        'product_id' => $product2->id,
        'random_product_id' => '456',
        'price_list_id' => 'LIST1',
        'unit' => 'UN',
        'price' => 700,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'LIST1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'UN', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['message' => 'Internal error', 'errorId' => 'abc']);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')
        ->with('Price list LIST1 sync stopped at page 2: Internal error')
        ->once();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('prices', ['random_product_id' => '123', 'price' => 999]);
    expect($notReached->fresh()->is_active)->toBeTrue();
});

test('job reactivates a deactivated price when Random returns it again', function () {
    User::factory()->create(['prices_lists' => ['LIST1']]);

    $product = Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => '123',
    ]);

    $price = Price::create([
        'product_id' => $product->id,
        'random_product_id' => '123',
        'price_list_id' => 'LIST1',
        'unit' => 'UN',
        'price' => 500,
        'is_active' => false,
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 1)
        ->once()
        ->andReturn([
            'nombre' => 'LIST1',
            'datos' => [
                [
                    'kopr' => '123',
                    'venderen' => 1,
                    'unidades' => [
                        ['nombre' => 'UN', 'prunneto' => [['f' => 999]]]
                    ]
                ]
            ]
        ]);
    $mock->shouldReceive('getPricesLists')
        ->with('LIST1', 100, 2)
        ->once()
        ->andReturn(['nombre' => 'LIST1', 'datos' => []]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $job = new SyncRandomPrices();
    $job->handle(app(RandomApiService::class));

    $price->refresh();
    expect($price->is_active)->toBeTrue();
    expect((float) $price->price)->toBe(999.0);
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
