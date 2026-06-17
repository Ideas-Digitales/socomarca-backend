<?php

use App\Models\Category;
use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('command executes job immediately', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn(['data' => []]);
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

test('command creates 3-level category hierarchy', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CONGELADOS', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CARNES', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'PESCADOS', 'NIVEL' => 3, 'LLAVE' => '0001/0001/0001'],
            ['CODIGO' => '0002', 'NOMBRE' => 'FRUTAS', 'NIVEL' => 3, 'LLAVE' => '0001/0001/0002'],
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')
        ->expectsOutput('Iniciando sincronización de categorías...')
        ->expectsOutput('Proceso de sincronización encolado correctamente.')
        ->assertExitCode(0);

    $this->assertDatabaseHas('categories', ['code' => '0001', 'level' => 1]);
    $this->assertDatabaseHas('categories', ['code' => '0001', 'level' => 2, 'key' => '0001/0001']);
    $this->assertDatabaseHas('categories', ['code' => '0001', 'level' => 3, 'key' => '0001/0001/0001']);
    $this->assertDatabaseHas('categories', ['code' => '0002', 'level' => 3, 'key' => '0001/0001/0002']);
});

test('command correctly links level 3 to parent level 2 category', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CONGELADOS', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CARNES', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'ROJO', 'NIVEL' => 3, 'LLAVE' => '0001/0001/0001'],
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    $level2 = Category::where('code', '0001')->where('level', 2)->first();
    $level3 = Category::where('level', 3)->where('key', '0001/0001/0001')->first();

    expect($level3->parent_category_id)->toBe($level2->id);
});

test('command handles multiple categories at each level', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0002', 'NOMBRE' => 'CAT2', 'NIVEL' => 1, 'LLAVE' => '0002'],
            ['CODIGO' => '0001', 'NOMBRE' => 'SUB1', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
            ['CODIGO' => '0002', 'NOMBRE' => 'SUB2', 'NIVEL' => 2, 'LLAVE' => '0001/0002'],
            ['CODIGO' => '0001', 'NOMBRE' => 'SUB3', 'NIVEL' => 2, 'LLAVE' => '0002/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CHILD1', 'NIVEL' => 3, 'LLAVE' => '0001/0001/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CHILD2', 'NIVEL' => 3, 'LLAVE' => '0001/0002/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CHILD3', 'NIVEL' => 3, 'LLAVE' => '0002/0001/0001'],
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::where('level', 1)->count())->toBe(2);
    expect(Category::where('level', 2)->count())->toBe(3);
    expect(Category::where('level', 3)->count())->toBe(3);
});

test('disables level 1 categories that no longer exist in service', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0002', 'NOMBRE' => 'CAT2', 'NIVEL' => 1, 'LLAVE' => '0002'],
        ]
    ])->once();
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::where('level', 1)->where('enabled', true)->count())->toBe(2);

    $mock2 = Mockery::mock(RandomApiService::class);
    $mock2->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
        ]
    ])->once();
    App::instance(RandomApiService::class, $mock2);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::where('level', 1)->where('enabled', true)->count())->toBe(1);
    expect(Category::where('code', '0002')->where('level', 1)->first()->enabled)->toBeFalse();
});

test('disables level 2 and 3 categories that no longer exist in service', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'SUB1', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
            ['CODIGO' => '0002', 'NOMBRE' => 'SUB2', 'NIVEL' => 2, 'LLAVE' => '0001/0002'],
        ]
    ])->once();
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::whereIn('level', [2, 3])->where('enabled', true)->count())->toBe(2);

    $mock2 = Mockery::mock(RandomApiService::class);
    $mock2->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'SUB1', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
        ]
    ])->once();
    App::instance(RandomApiService::class, $mock2);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::whereIn('level', [2, 3])->where('enabled', true)->count())->toBe(1);
    expect(Category::where('key', '0001/0002')->whereIn('level', [2, 3])->first()->enabled)->toBeFalse();
});

test('marks all synced categories as enabled', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getCategories')->andReturn([
        'data' => [
            ['CODIGO' => '0001', 'NOMBRE' => 'CAT1', 'NIVEL' => 1, 'LLAVE' => '0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'SUB1', 'NIVEL' => 2, 'LLAVE' => '0001/0001'],
            ['CODIGO' => '0001', 'NOMBRE' => 'CHILD1', 'NIVEL' => 3, 'LLAVE' => '0001/0001/0001'],
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->twice();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $this->artisan('random:sync-categories')->assertExitCode(0);

    expect(Category::where('code', '0001')->where('level', 1)->first()->enabled)->toBeTrue();
    expect(Category::where('key', '0001/0001')->where('level', 2)->first()->enabled)->toBeTrue();
    expect(Category::where('key', '0001/0001/0001')->where('level', 3)->first()->enabled)->toBeTrue();
});
