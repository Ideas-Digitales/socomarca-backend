<?php

use App\Enums\BranchType;
use App\Jobs\SyncRandomBranches;
use App\Models\Branch;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('sync creates branches for secondary sucursales', function () {
    $user = User::factory()->create(['user_code' => '12345678-9']);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN'        => '12345678-9',
            'RTEN'        => '11111111-1',
            'NOKOEN'      => 'Branch One',
            'EMAIL'       => 'branch1@example.com',
            'EMAILCOMER'  => 'comercial1@example.com',
            'FOEN'        => '56977777777',
            'SIEN'        => 'Branch One Business',
            'SUEN'        => 'BRANCH001',
            'TIPOSUC'     => 'S',
        ],
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('critical')->zeroOrMoreTimes();

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('branches', [
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

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN'        => '12345678-9',
            'RTEN'        => '11111111-1',
            'NOKOEN'      => 'Primary Branch',
            'EMAIL'       => 'primary@example.com',
            'EMAILCOMER'  => 'comercial@example.com',
            'FOEN'        => '56977777777',
            'SIEN'        => 'Primary Business',
            'SUEN'        => 'BRANCH999',
            'TIPOSUC'     => 'P',
        ],
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('critical')->zeroOrMoreTimes();

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('branches', [
        'user_code'   => '12345678-9',
        'code'        => 'BRANCH999',
        'branch_type' => 'P',
    ]);
});

test('sync skips when parent user does not exist', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN'        => '99999999-9',
            'RTEN'        => '11111111-1',
            'NOKOEN'      => 'Orphan Branch',
            'EMAIL'       => 'orphan@example.com',
            'EMAILCOMER'  => 'comercial@example.com',
            'FOEN'        => '56977777777',
            'SIEN'        => 'Orphan Business',
            'SUEN'        => 'ORPHAN01',
            'TIPOSUC'     => 'S',
        ],
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('critical')->zeroOrMoreTimes();

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseMissing('branches', [
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

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN'        => '12345678-9',
            'RTEN'        => '22222222-2',
            'NOKOEN'      => 'Updated Name',
            'EMAIL'       => 'new@example.com',
            'EMAILCOMER'  => 'newcom@example.com',
            'FOEN'        => '56988888888',
            'SIEN'        => 'Updated Business',
            'SUEN'        => 'BRANCH001',
            'TIPOSUC'     => 'S',
        ],
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('critical')->zeroOrMoreTimes();

    $job = new SyncRandomBranches();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('branches', [
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
    $this->assertEquals(1, $total);
});
