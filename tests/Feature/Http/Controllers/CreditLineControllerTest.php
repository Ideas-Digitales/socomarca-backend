<?php

use App\Models\User;
use App\Services\RandomApiService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);

    Permission::firstOrCreate(['name' => 'read-own-credit-line']);
    Permission::firstOrCreate(['name' => 'read-all-credit-line']);
});

describe('Credit Line endpoint', function () {
    it('should return 401 without authentication', function () {
        $user = User::factory()->create();

        $this->getJson(route('users.credit-lines', ['user' => $user->id]))
            ->assertStatus(401);
    });

    it('should return 403 when user does not have read-own-credit-line permission', function () {
        $user = User::factory()->create(['rut' => '12345678-5', 'sucursal_code' => '001']);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => $user->id]))
            ->assertForbidden();
    });

    it('should return 403 when trying to view another user credit line without read-all-credit-line permission', function () {
        $admin = User::factory()->create();
        $admin->givePermissionTo('read-own-credit-line');

        $targetUser = User::factory()->create(['rut' => '12345678-5', 'sucursal_code' => '001']);

        $this->actingAs($admin, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => $targetUser->id]))
            ->assertForbidden();
    });

    it('should return credit line data when user has read-own-credit-line permission', function () {
        $user = User::factory()->create(['rut' => '12345678-5', 'sucursal_code' => '001']);
        $user->givePermissionTo('read-own-credit-line');

        $mockResponse = [
            'credit_limit' => 5000000,
            'available_credit' => 3000000,
            'used_credit' => 2000000,
        ];

        $mock = \Mockery::mock(RandomApiService::class);
        $mock->shouldReceive('getCreditLine')
            ->once()
            ->with('12345678-5', '001')
            ->andReturn($mockResponse);

        app()->forgetInstance(RandomApiService::class);
        app()->instance(RandomApiService::class, $mock);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => $user->id]))
            ->assertStatus(200)
            ->assertJson($mockResponse);
    });

    it('should return credit line data when admin has read-all-credit-line permission', function () {
        $admin = User::factory()->create();
        $admin->givePermissionTo('read-all-credit-line');

        $targetUser = User::factory()->create(['rut' => '87654321-9', 'sucursal_code' => '002']);

        $mockResponse = [
            'credit_limit' => 10000000,
            'available_credit' => 7000000,
            'used_credit' => 3000000,
        ];

        $mock = \Mockery::mock(RandomApiService::class);
        $mock->shouldReceive('getCreditLine')
            ->once()
            ->with('87654321-9', '002')
            ->andReturn($mockResponse);

        app()->forgetInstance(RandomApiService::class);
        app()->instance(RandomApiService::class, $mock);

        $this->actingAs($admin, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => $targetUser->id]))
            ->assertStatus(200)
            ->assertJson($mockResponse);
    });

    it('should return 404 when user does not exist', function () {
        $admin = User::factory()->create();
        $admin->givePermissionTo('read-all-credit-line');

        $this->actingAs($admin, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => 99999]))
            ->assertStatus(404);
    });

    it('should return 500 when RandomApiService throws an exception', function () {
        $user = User::factory()->create(['rut' => '12345678-5', 'sucursal_code' => '001']);
        $user->givePermissionTo('read-own-credit-line');

        $mock = \Mockery::mock(RandomApiService::class);
        $mock->shouldReceive('getCreditLine')
            ->once()
            ->with('12345678-5', '001')
            ->andThrow(new \Exception('Service unavailable'));

        app()->forgetInstance(RandomApiService::class);
        app()->instance(RandomApiService::class, $mock);

        $this->actingAs($user, 'sanctum')
            ->getJson(route('users.credit-lines', ['user' => $user->id]))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Error al obtener la línea de crédito');
    });
});
