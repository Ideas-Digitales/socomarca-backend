<?php

use App\Jobs\SyncRandomUsers;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('sync users creates new user with generated email when email is empty', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getEntidadesUsuarios')->andReturn(
        [
            [
                'KOEN' => '12345678-9',
                'RTEN' => '11111111-1',
                'NOKOEN' => 'John Doe',
                'EMAILCOMER' => '',
                'SIEN' => 'John Doe Business',
                'FOEN' => '+56912345678',
                'SUEN' => 'BRANCH001',
                'TIPOSUC' => 'P',
                'TIEN' => 'C',
            ]
        ],
        []
    );
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('users', [
        'user_code' => '12345678-9',
        'rut' => '11111111-1',
        'name' => 'John Doe',
        'email' => 'temp_11111111-1@socomarca.temp',
        'business_name' => 'John Doe Business',
    ]);

    $user = User::where('user_code', '12345678-9')->first();
    expect($user->hasRole('customer'))->toBeTrue();
});

test('sync users allows duplicate email with different user', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
        'rut' => '98765432-1',
        'user_code' => '98765432-1',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getEntidadesUsuarios')->andReturn(
        [
            [
                'KOEN' => '12345678-9',
                'RTEN' => '11111111-1',
                'NOKOEN' => 'John Doe',
                'EMAILCOMER' => 'existing@example.com',
                'SIEN' => 'John Doe Business',
                'FOEN' => '+56912345678',
                'SUEN' => 'BRANCH001',
                'TIPOSUC' => 'P',
                'TIEN' => 'C',
            ]
        ],
        []
    );
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('users', [
        'user_code' => '12345678-9',
        'rut' => '11111111-1',
        'email' => 'existing@example.com',
    ]);
});

test('sync users allows same user to update email by user_code', function () {
    User::factory()->create([
        'user_code' => '12345678-9',
        'rut' => '11111111-1',
        'name' => 'John Doe',
        'email' => 'old@example.com',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getEntidadesUsuarios')->andReturn(
        [
            [
                'KOEN' => '12345678-9',
                'RTEN' => '11111111-1',
                'NOKOEN' => 'John Doe Updated',
                'EMAILCOMER' => 'new@example.com',
                'SIEN' => 'John Doe Business Updated',
                'FOEN' => '+56912345678',
                'SUEN' => 'BRANCH001',
                'TIPOSUC' => 'P',
                'TIEN' => 'A',
            ]
        ],
        []
    );
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('users', [
        'user_code' => '12345678-9',
        'rut' => '11111111-1',
        'email' => 'new@example.com',
        'name' => 'John Doe Updated',
        'business_name' => 'John Doe Business Updated',
    ]);
});

test('sync users skips non primary branch sucursales', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getEntidadesUsuarios')->andReturn(
        [
            [
                'KOEN' => '12345678-9',
                'RTEN' => '11111111-1',
                'NOKOEN' => 'John Doe',
                'EMAILCOMER' => 'john@example.com',
                'SIEN' => 'John Doe Business',
                'FOEN' => '+56912345678',
                'SUEN' => 'BRANCH001',
                'TIPOSUC' => 'S',
                'TIEN' => 'C',
            ]
        ],
        []
    );
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseMissing('users', [
        'user_code' => '12345678-9',
    ]);
});
