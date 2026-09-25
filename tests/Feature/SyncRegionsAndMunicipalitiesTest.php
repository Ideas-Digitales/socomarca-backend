<?php

use App\Models\Municipality;
use App\Models\Region;
use App\Services\RandomApiService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\App;

function mockTerritoriosResponse(array $data): void
{
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('json')->with('data')->andReturn($data);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getTerritorios')->with(3)->andReturn($response);

    App::instance(RandomApiService::class, $mock);
}

describe('random:sync-regions-municipalities', function () {
    it('creates regions from the Random API', function () {
        mockTerritoriosResponse([
            ['CODIGO' => '5', 'NOMBRE' => 'V Region de Valparaiso', 'NIVEL' => 2, 'LLAVE' => 'CL/5'],
            ['CODIGO' => '13', 'NOMBRE' => 'Region Metropolitana', 'NIVEL' => 2, 'LLAVE' => 'CL/13'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        expect(Region::count())->toBe(2);

        $this->assertDatabaseHas('regions', [
            'code' => '05',
            'name' => 'V Region de Valparaiso',
            'random_key' => 'CL/5',
            'random_name' => 'V Region de Valparaiso',
            'status' => true,
        ]);

        $this->assertDatabaseHas('regions', [
            'code' => '13',
            'name' => 'Region Metropolitana',
            'random_key' => 'CL/13',
            'random_name' => 'Region Metropolitana',
        ]);
    });

    it('updates random fields without overwriting the existing display name', function () {
        $region = Region::factory()->create([
            'code' => '05',
            'name' => 'Valparaíso',
            'random_key' => 'missing',
            'random_name' => 'missing',
        ]);

        mockTerritoriosResponse([
            ['CODIGO' => '5', 'NOMBRE' => 'V Region de Valparaiso', 'NIVEL' => 2, 'LLAVE' => 'CL/5'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        $region->refresh();

        expect($region->name)->toBe('Valparaíso')
            ->and($region->random_key)->toBe('CL/5')
            ->and($region->random_name)->toBe('V Region de Valparaiso')
            ->and(Region::count())->toBe(1);
    });

    it('creates municipalities linked to the matching region', function () {
        mockTerritoriosResponse([
            ['CODIGO' => '5', 'NOMBRE' => 'V Region de Valparaiso', 'NIVEL' => 2, 'LLAVE' => 'CL/5'],
            ['CODIGO' => 'ALG', 'NOMBRE' => 'Algarrobo', 'NIVEL' => 3, 'LLAVE' => 'CL/5/ALG'],
            ['CODIGO' => 'QUI', 'NOMBRE' => 'Quilpue', 'NIVEL' => 3, 'LLAVE' => 'CL/5/QUI'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        $region = Region::where('code', '05')->first();

        expect(Municipality::count())->toBe(2);

        $this->assertDatabaseHas('municipalities', [
            'name' => 'Algarrobo',
            'region_id' => $region->id,
            'random_key' => 'CL/5/ALG',
            'random_name' => 'Algarrobo',
            'status' => true,
        ]);

        $this->assertDatabaseHas('municipalities', [
            'name' => 'Quilpue',
            'region_id' => $region->id,
            'random_key' => 'CL/5/QUI',
            'random_name' => 'Quilpue',
        ]);
    });

    it('matches an existing municipality by accent-insensitive name within its region', function () {
        $region = Region::factory()->create([
            'code' => '13',
            'name' => 'Metropolitana de Santiago',
        ]);

        $municipality = Municipality::factory()->create([
            'name' => 'Ñuñoa',
            'code' => '13119',
            'region_id' => $region->id,
            'random_key' => 'missing',
            'random_name' => 'missing',
        ]);

        mockTerritoriosResponse([
            ['CODIGO' => '13', 'NOMBRE' => 'Region Metropolitana', 'NIVEL' => 2, 'LLAVE' => 'CL/13'],
            ['CODIGO' => 'NUN', 'NOMBRE' => 'NuNoa', 'NIVEL' => 3, 'LLAVE' => 'CL/13/NUN'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        $municipality->refresh();

        expect($municipality->name)->toBe('Ñuñoa')
            ->and($municipality->code)->toBe('13119')
            ->and($municipality->random_key)->toBe('CL/13/NUN')
            ->and($municipality->random_name)->toBe('NuNoa')
            ->and($municipality->region_id)->toBe($region->id)
            ->and(Municipality::count())->toBe(1);
    });

    it('matches an existing municipality when the API name contains the system name', function () {
        $region = Region::factory()->create([
            'code' => '12',
            'name' => 'Magallanes y Antártica Chilena',
        ]);

        $municipality = Municipality::factory()->create([
            'name' => 'Natales',
            'code' => '12401',
            'region_id' => $region->id,
            'random_key' => 'missing',
            'random_name' => 'missing',
        ]);

        mockTerritoriosResponse([
            ['CODIGO' => '12', 'NOMBRE' => 'XII Region de Magallanes', 'NIVEL' => 2, 'LLAVE' => 'CL/12'],
            ['CODIGO' => 'PUE', 'NOMBRE' => 'Puerto Natales', 'NIVEL' => 3, 'LLAVE' => 'CL/12/PUE'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        $municipality->refresh();

        expect($municipality->name)->toBe('Natales')
            ->and($municipality->random_key)->toBe('CL/12/PUE')
            ->and($municipality->random_name)->toBe('Puerto Natales')
            ->and(Municipality::count())->toBe(1);
    });

    it('is idempotent when matching by random_key on a second run', function () {
        mockTerritoriosResponse([
            ['CODIGO' => '5', 'NOMBRE' => 'V Region de Valparaiso', 'NIVEL' => 2, 'LLAVE' => 'CL/5'],
            ['CODIGO' => 'ALG', 'NOMBRE' => 'Algarrobo', 'NIVEL' => 3, 'LLAVE' => 'CL/5/ALG'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();
        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        expect(Region::count())->toBe(1)
            ->and(Municipality::count())->toBe(1);

        $this->assertDatabaseHas('municipalities', [
            'random_key' => 'CL/5/ALG',
            'random_name' => 'Algarrobo',
        ]);
    });

    it('skips municipalities whose region cannot be resolved from LLAVE', function () {
        mockTerritoriosResponse([
            ['CODIGO' => 'ORF', 'NOMBRE' => 'Orphan Comuna', 'NIVEL' => 3, 'LLAVE' => 'CL/99/ORF'],
        ]);

        $this->artisan('random:sync-regions-municipalities')
            ->expectsOutputToContain('Skipped: 1')
            ->assertSuccessful();

        expect(Municipality::count())->toBe(0);
    });

    it('does not match municipalities from a different region by name', function () {
        $valparaiso = Region::factory()->create([
            'code' => '05',
            'name' => 'Valparaíso',
        ]);
        $metro = Region::factory()->create([
            'code' => '13',
            'name' => 'Metropolitana de Santiago',
        ]);

        Municipality::factory()->create([
            'name' => 'San Pedro',
            'code' => '05602',
            'region_id' => $valparaiso->id,
        ]);

        mockTerritoriosResponse([
            ['CODIGO' => '13', 'NOMBRE' => 'Region Metropolitana', 'NIVEL' => 2, 'LLAVE' => 'CL/13'],
            ['CODIGO' => '05', 'NOMBRE' => 'V Region de Valparaiso', 'NIVEL' => 2, 'LLAVE' => 'CL/5'],
            ['CODIGO' => 'SPE', 'NOMBRE' => 'San Pedro', 'NIVEL' => 3, 'LLAVE' => 'CL/13/SPE'],
        ]);

        $this->artisan('random:sync-regions-municipalities')->assertSuccessful();

        expect(Municipality::where('name', 'San Pedro')->count())->toBe(2);

        $this->assertDatabaseHas('municipalities', [
            'name' => 'San Pedro',
            'region_id' => $metro->id,
            'random_key' => 'CL/13/SPE',
        ]);

        $this->assertDatabaseHas('municipalities', [
            'name' => 'San Pedro',
            'region_id' => $valparaiso->id,
            'code' => '05602',
            'random_key' => 'missing',
        ]);
    });
});
