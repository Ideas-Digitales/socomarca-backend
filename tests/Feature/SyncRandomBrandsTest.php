<?php

use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('command executes job immediately', function () {
    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getBrands')->andReturn([
        'data' => []
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomBrands started')
        ->once();
    Log::shouldReceive('info')
        ->with('SyncRandomBrands finished')
        ->once();

    $this->artisan('random:sync-brands')
        ->expectsOutput('Iniciando sincronización de marcas...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);
});

test('command executes job and creates brands', function () {
    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getBrands')->andReturn([
        'data' => [
            [
                'MRPR' => 'BRAND001',
                'NOKOMR' => 'Marca Test 1'
            ],
            [
                'MRPR' => 'BRAND002',
                'NOKOMR' => 'Marca Test 2'
            ],
            [
                'MRPR' => '',
                'NOKOMR' => 'Marca Sin Código'
            ]
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomBrands started')
        ->once();
    Log::shouldReceive('info')
        ->with('SyncRandomBrands finished')
        ->once();
    Log::shouldReceive('info')
        ->with(Mockery::pattern('/SyncRandomBrands:/'))
        ->times(3);

    $this->artisan('random:sync-brands')
        ->expectsOutput('Iniciando sincronización de marcas...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);

    // Verifica que las marcas fueron creadas
    $this->assertDatabaseHas('brands', [
        'random_erp_code' => 'BRAND001',
        'name' => 'Marca Test 1'
    ]);
    $this->assertDatabaseHas('brands', [
        'random_erp_code' => 'BRAND002',
        'name' => 'Marca Test 2'
    ]);
    // La marca sin código no debe ser creada
    $this->assertDatabaseMissing('brands', [
        'name' => 'Marca Sin Código'
    ]);
});

test('command handles brand without name using code as fallback', function () {
    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getBrands')->andReturn([
        'data' => [
            [
                'MRPR' => 'BRAND003',
                'NOKOMR' => ''
            ]
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomBrands started')
        ->once();
    Log::shouldReceive('info')
        ->with('SyncRandomBrands finished')
        ->once();
    Log::shouldReceive('info')
        ->with(Mockery::pattern('/SyncRandomBrands:/'))
        ->once();

    $this->artisan('random:sync-brands')
        ->expectsOutput('Iniciando sincronización de marcas...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);

    // Verifica que la marca usa el código como nombre
    $this->assertDatabaseHas('brands', [
        'random_erp_code' => 'BRAND003',
        'name' => 'BRAND003'
    ]);
});

