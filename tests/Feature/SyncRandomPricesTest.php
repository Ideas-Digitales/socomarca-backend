<?php

use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

uses(RefreshDatabase::class);

test('command executes job immediately', function () {
    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')->andReturn([
        'nombre' => 1,
        'datos' => []
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomPrices started')
        ->once();
    Log::shouldReceive('info')
        ->with('No hay datos de precios para procesar')
        ->once();

    $this->artisan('random:sync-prices')
        ->expectsOutput('Iniciando sincronización de precios...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);
});

test('command executes job and updates product prices', function () {
    Product::create([
        'code' => 'PROD1',
        'sku' => 'SKU1',
        'name' => 'Producto 1',
        'price' => 1000,
        'random_product_id' => 123,
    ]);

    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getPricesLists')->andReturn([
        'nombre' => 1,
        'datos' => [
            [
                'kopr' => 123,
                'venderen' => 1,
                'unidades' => [
                    [
                        'nombre' => 'kg',
                        'prunneto' => [
                            ['f' => 999]
                        ]
                    ]
                ]
            ]
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->with('SyncRandomPrices started')->once();
    Log::shouldReceive('info')->with('SyncRandomPrices finished')->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-prices')
        ->expectsOutput('Iniciando sincronización de precios...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);

    // Verificar que se creó un precio
    $this->assertDatabaseHas('prices', [
        'unit' => 'kg',
        'is_active' => true,
    ]);
});

