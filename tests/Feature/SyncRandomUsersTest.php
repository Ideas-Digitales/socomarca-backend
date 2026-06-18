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
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN' => '12345678-9',
            'NOKOEN' => 'John Doe',
            'EMAILCOMER' => '',
            'SIEN' => 'John Doe Business',
            'FOEN' => '+56912345678',
            'SUEN' => 'BRANCH001',
            'TIPOSUC' => 'P',
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('users', [
        'rut' => '12345678-9',
        'name' => 'John Doe',
        'email' => 'temp_12345678-9@socomarca.temp',
    ]);
});

test('sync users prevents duplicate email with different user', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
        'rut' => '98765432-1',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN' => '12345678-9',
            'NOKOEN' => 'John Doe',
            'EMAILCOMER' => 'existing@example.com',
            'SIEN' => 'John Doe Business',
            'FOEN' => '+56912345678',
            'SUEN' => 'BRANCH001',
            'TIPOSUC' => 'P',
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')
        ->withArgs(function ($message) {
            return str_contains($message, 'Email ya existe');
        })
        ->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseMissing('users', [
        'rut' => '12345678-9',
    ]);
});

test('sync users allows same user to update email by rut', function () {
    User::factory()->create([
        'rut' => '12345678-9',
        'name' => 'John Doe',
        'email' => 'old@example.com',
    ]);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN' => '12345678-9',
            'NOKOEN' => 'John Doe Updated',
            'EMAILCOMER' => 'new@example.com',
            'SIEN' => 'John Doe Business Updated',
            'FOEN' => '+56912345678',
            'SUEN' => 'BRANCH001',
            'TIPOSUC' => 'P',
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseHas('users', [
        'rut' => '12345678-9',
        'email' => 'new@example.com',
        'name' => 'John Doe Updated',
        'business_name' => 'John Doe Business Updated',
    ]);
});

test('sync users skips non primary branch sucursales', function () {
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('fetchAndUpdateUsers')->andReturn([
        [
            'KOEN' => '12345678-9',
            'NOKOEN' => 'John Doe',
            'EMAILCOMER' => 'john@example.com',
            'SIEN' => 'John Doe Business',
            'FOEN' => '+56912345678',
            'SUEN' => 'BRANCH001',
            'TIPOSUC' => 'S', // Secondary branch
        ]
    ]);
    App::instance(RandomApiService::class, $mock);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $job = new SyncRandomUsers();
    $job->handle(app(RandomApiService::class));

    $this->assertDatabaseMissing('users', [
        'rut' => '12345678-9',
    ]);
});
