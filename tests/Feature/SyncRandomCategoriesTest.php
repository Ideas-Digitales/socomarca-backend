<?php

use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('command executes job immediately', function () {
    // Mock del servicio
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => []
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomCategories started')
        ->once();
    Log::shouldReceive('info')
        ->with('SyncRandomCategories finished')
        ->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')
        ->expectsOutput('Iniciando sincronización de categorías...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);
});

test('command executes job and creates categories', function () {
    // Mock del servicio con datos de categorías
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            [
                'CODIGO' => 'ACTF',
                'NOMBRE' => 'ACTUADORES FLUIDICOS',
                'NIVEL' => 1,
                'LLAVE' => 'ACTF'
            ],
            [
                'CODIGO' => 'ASES',
                'NOMBRE' => 'ASIENTO ESCOTICO',
                'NIVEL' => 2,
                'LLAVE' => 'ACTF/ASES'
            ]
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')
        ->with('SyncRandomCategories started')
        ->once();
    Log::shouldReceive('info')
        ->with('SyncRandomCategories finished')
        ->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')
        ->expectsOutput('Iniciando sincronización de categorías...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);

    // Verificar que se crearon las categorías y subcategorías
    $this->assertDatabaseHas('categories', [
        'code' => 'ACTF',
        'name' => 'ACTUADORES FLUIDICOS'
    ]);
    $this->assertDatabaseHas('subcategories', [
        'code' => 'ASES',
        'name' => 'ASIENTO ESCOTICO'
    ]);
});