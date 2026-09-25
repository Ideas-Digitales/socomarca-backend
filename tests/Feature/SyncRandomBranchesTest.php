<?php

use App\Enums\BranchType;
use App\Jobs\SyncRandomBranches;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Municipality;
use App\Models\Region;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function PHPUnit\Framework\assertEquals;

function mockBranchSync(array $entities): void
{
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn($entities);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('critical')->zeroOrMoreTimes();
}

function seedQuinteroMunicipality(): Municipality
{
    $region = Region::factory()->create([
        'code' => '05',
        'name' => 'Valparaíso',
        'random_key' => 'CL/5',
        'random_name' => 'V Region de Valparaiso',
    ]);

    return Municipality::factory()->create([
        'name' => 'Quintero',
        'region_id' => $region->id,
        'random_key' => 'CL/5/QIN',
        'random_name' => 'Quintero',
    ]);
}

test('sync creates branches for secondary sucursales', function () {
    $user = User::factory()->create(['user_code' => '12345678-9']);

    mockBranchSync([
        [
            'KOEN'       => '12345678-9',
            'RTEN'       => '11111111-1',
            'NOKOEN'     => 'Branch One',
            'EMAIL'      => 'branch1@example.com',
            'EMAILCOMER' => 'comercial1@example.com',
            'FOEN'       => '56977777777',
            'SIEN'       => 'Branch One Business',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
        ],
    ]);

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    assertDatabaseHas('branches', [
        'user_code'        => '12345678-9',
        'code'             => 'BRANCH001',
        'name'             => 'Branch One',
        'email'            => 'branch1@example.com',
        'commercial_email' => 'comercial1@example.com',
        'phone'            => '56977777777',
        'rut'              => '11111111-1',
        'business_name'    => 'Branch One Business',
        'user_id'          => $user->id,
        'branch_type'      => 'S',
    ]);
});

test('sync creates primary branches', function () {
    $user = User::factory()->create(['user_code' => '12345678-9']);

    mockBranchSync([
        [
            'KOEN'       => '12345678-9',
            'RTEN'       => '11111111-1',
            'NOKOEN'     => 'Primary Branch',
            'EMAIL'      => 'primary@example.com',
            'EMAILCOMER' => 'comercial@example.com',
            'FOEN'       => '56977777777',
            'SIEN'       => 'Primary Business',
            'SUEN'       => 'BRANCH999',
            'TIPOSUC'    => 'P',
        ],
    ]);

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    assertDatabaseHas('branches', [
        'user_code'   => '12345678-9',
        'code'        => 'BRANCH999',
        'branch_type' => 'P',
    ]);
});

test('sync skips when parent user does not exist', function () {
    mockBranchSync([
        [
            'KOEN'       => '99999999-9',
            'RTEN'       => '11111111-1',
            'NOKOEN'     => 'Orphan Branch',
            'EMAIL'      => 'orphan@example.com',
            'EMAILCOMER' => 'comercial@example.com',
            'FOEN'       => '56977777777',
            'SIEN'       => 'Orphan Business',
            'SUEN'       => 'ORPHAN01',
            'TIPOSUC'    => 'S',
        ],
    ]);

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    assertDatabaseMissing('branches', [
        'user_code' => '99999999-9',
    ]);
});

test('sync updates existing branch via upsert', function () {
    $user = User::factory()->create(['user_code' => '12345678-9']);
    Branch::factory()->create([
        'user_id'          => $user->id,
        'user_code'        => '12345678-9',
        'code'             => 'BRANCH001',
        'name'             => 'Old Name',
        'email'            => 'old@example.com',
        'commercial_email' => 'oldcom@example.com',
        'rut'              => '11111111-1',
        'branch_type'      => BranchType::SECONDARY,
    ]);

    mockBranchSync([
        [
            'KOEN'       => '12345678-9',
            'RTEN'       => '22222222-2',
            'NOKOEN'     => 'Updated Name',
            'EMAIL'      => 'new@example.com',
            'EMAILCOMER' => 'newcom@example.com',
            'FOEN'       => '56988888888',
            'SIEN'       => 'Updated Business',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
        ],
    ]);

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    assertDatabaseHas('branches', [
        'user_code'        => '12345678-9',
        'code'             => 'BRANCH001',
        'name'             => 'Updated Name',
        'email'            => 'new@example.com',
        'commercial_email' => 'newcom@example.com',
        'phone'            => '56988888888',
        'rut'              => '22222222-2',
        'business_name'    => 'Updated Business',
        'branch_type'      => 'S',
    ]);

    $total = DB::table('branches')->count();
    assertEquals(1, $total);
});

test('sync creates a shipping address for a secondary branch using CL/{CIEN}/{CMEN}', function () {
    $user = User::factory()->create(['user_code' => '15065160']);
    $municipality = seedQuinteroMunicipality();

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '56911111111',
            'SIEN'       => '',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
            'PAEN'       => 'CHI',
            'CIEN'       => '5',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '2480000',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    $branch = DB::table('branches')
        ->where('code', 'BRANCH001')
        ->where('user_code', '15065160')
        ->first();

    assertDatabaseHas('addresses', [
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'region_id' => $municipality->region_id,
        'municipality_id' => $municipality->id,
        'address_line1' => 'GARRETON ARCE 1698',
        'postal_code' => '2480000',
        'phone' => '56911111111',
        'contact_name' => 'DENISSE ABARCAMONTES',
        'type' => 'shipping',
        'is_default' => false,
    ]);
});

test('sync creates a billing address for a primary branch', function () {
    $user = User::factory()->create(['user_code' => '15065160']);
    $municipality = seedQuinteroMunicipality();

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '',
            'SIEN'       => '',
            'SUEN'       => '',
            'TIPOSUC'    => 'P',
            'PAEN'       => 'CHI',
            'CIEN'       => '5',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    $branch = DB::table('branches')
        ->where('code', '')
        ->where('user_code', '15065160')
        ->first();

    assertDatabaseHas('addresses', [
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'region_id' => $municipality->region_id,
        'municipality_id' => $municipality->id,
        'address_line1' => 'GARRETON ARCE 1698',
        'type' => 'billing',
    ]);
});

test('sync updates the existing address for a branch', function () {
    $user = User::factory()->create(['user_code' => '15065160']);
    $municipality = seedQuinteroMunicipality();
    $otherMunicipality = Municipality::factory()->create([
        'region_id' => $municipality->region_id,
        'random_key' => 'CL/5/ALG',
        'random_name' => 'Algarrobo',
    ]);

    $branch = Branch::factory()->create([
        'user_id' => $user->id,
        'user_code' => '15065160',
        'code' => 'BRANCH001',
        'branch_type' => BranchType::SECONDARY,
    ]);

    Address::factory()->create([
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'region_id' => $otherMunicipality->region_id,
        'municipality_id' => $otherMunicipality->id,
        'address_line1' => 'Old Street 1',
        'type' => 'shipping',
    ]);

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '56922222222',
            'SIEN'       => '',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
            'PAEN'       => 'CHI',
            'CIEN'       => '5',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '2480000',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    expect(Address::where('branch_id', $branch->id)->count())->toBe(1);

    assertDatabaseHas('addresses', [
        'branch_id' => $branch->id,
        'region_id' => $municipality->region_id,
        'municipality_id' => $municipality->id,
        'address_line1' => 'GARRETON ARCE 1698',
        'phone' => '56922222222',
        'postal_code' => '2480000',
    ]);
});

test('sync skips address when region random_key does not exist', function () {
    User::factory()->create(['user_code' => '15065160']);
    Municipality::factory()->create([
        'random_key' => 'CL/5/QIN',
        'random_name' => 'Quintero',
    ]);

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '',
            'SIEN'       => '',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
            'PAEN'       => 'CHI',
            'CIEN'       => '5',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    assertDatabaseHas('branches', [
        'user_code' => '15065160',
        'code' => 'BRANCH001',
    ]);
    expect(Address::count())->toBe(0);
});

test('sync skips address when municipality random_key does not exist', function () {
    User::factory()->create(['user_code' => '15065160']);

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '',
            'SIEN'       => '',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
            'PAEN'       => 'CHI',
            'CIEN'       => '5',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    assertDatabaseHas('branches', [
        'user_code' => '15065160',
        'code' => 'BRANCH001',
    ]);
    expect(Address::count())->toBe(0);
});

test('sync skips address when CIEN or CMEN is missing', function () {
    User::factory()->create(['user_code' => '15065160']);
    seedQuinteroMunicipality();

    mockBranchSync([
        [
            'KOEN'       => '15065160',
            'RTEN'       => '15065160',
            'NOKOEN'     => 'DENISSE ABARCAMONTES',
            'EMAIL'      => 'DENISABARCA@HOTMAIL.COM',
            'EMAILCOMER' => '',
            'FOEN'       => '',
            'SIEN'       => '',
            'SUEN'       => 'BRANCH001',
            'TIPOSUC'    => 'S',
            'PAEN'       => 'CHI',
            'CIEN'       => '',
            'CMEN'       => 'QIN',
            'DIEN'       => 'GARRETON ARCE 1698',
            'CPOSTAL'    => '',
        ],
    ]);

    (new SyncRandomBranches())->handle(app(RandomApiService::class));

    expect(Address::count())->toBe(0);
});
