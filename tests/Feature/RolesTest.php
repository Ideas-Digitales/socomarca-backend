<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Crear roles
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'superadmin']);
    Role::firstOrCreate(['name' => 'customer']);
    Role::firstOrCreate(['name' => 'supervisor']);
    Role::firstOrCreate(['name' => 'editor']);
    
    // Crear permisos relevantes para testing
    Permission::firstOrCreate(['name' => 'read-all-roles']);
    Permission::firstOrCreate(['name' => 'read-user-roles']);
    Permission::firstOrCreate(['name' => 'read-all-reports']);
    Permission::firstOrCreate(['name' => 'read-all-permissions']);
});

describe('read-all-roles permission', function () {
    test('users with read-all-roles permission can access roles index', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-roles');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(200);
    });

    test('users without read-all-roles permission cannot access roles index', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(403);
    });

    test('unauthenticated users cannot access roles index', function () {
        $response = $this->getJson('/api/roles');

        $response->assertStatus(401);
    });

    test('roles index returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-roles');

        // Asignar algunos permisos a un rol para verificar la estructura
        $role = Role::findByName('admin');
        $role->givePermissionTo(['read-all-reports']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'permissions',
                    'created_at',
                    'updated_at'
                ]
            ]);

        // Verificar que devuelve al menos los roles básicos
        $data = $response->json();
        $roleNames = array_column($data, 'name');
        
        expect($roleNames)->toContain('admin');
        expect($roleNames)->toContain('customer');
        expect($roleNames)->toContain('superadmin');
        expect($roleNames)->toContain('supervisor');
        expect($roleNames)->toContain('editor');

        // Verificar estructura de permisos
        $adminRole = collect($data)->firstWhere('name', 'admin');
        expect($adminRole)->not->toBeNull();
        expect($adminRole['permissions'])->toBeArray();
    });
});

describe('read-user-roles permission', function () {
    test('users with read-user-roles permission can access roles with users', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(200);
    });

    test('users without read-user-roles permission cannot access roles with users', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(403);
    });

    test('users with read-user-roles permission can access specific user roles', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        
        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(200);
    });

    test('users without read-user-roles permission cannot access specific user roles', function () {
        $user = User::factory()->create();
        // No se asigna el permiso
        
        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(403);
    });

    test('roles with users returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        
        // Crear usuario con rol para verificar estructura
        $testUser = User::factory()->create();
        $testUser->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'role',
                    'users' => [
                        '*' => [
                            'id',
                            'name',
                            'email'
                        ]
                    ]
                ]
            ]);
    });

    test('user roles returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        
        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user_id',
                'user_name',
                'roles',
                'permissions'
            ])
            ->assertJsonPath('user_id', $targetUser->id)
            ->assertJsonPath('user_name', $targetUser->name);
            
        $data = $response->json();
        expect($data['roles'])->toBeArray();
        expect($data['permissions'])->toBeArray();
    });

    test('unauthenticated users cannot access roles with users', function () {
        $response = $this->getJson('/api/roles/users');

        $response->assertStatus(401);
    });

    test('unauthenticated users cannot access specific user roles', function () {
        $targetUser = User::factory()->create();
        
        $response = $this->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(401);
    });
});

describe('read-all-reports permission', function () {
    test('users with read-all-reports permission can access reports dashboard', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/dashboard', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(200);
    });

    test('users with read-all-reports permission can access reports transactions', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/transactions', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(200);
    });

    test('users with read-all-reports permission can export reports', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/transactions/export', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(200);
    });

    test('users without read-all-reports permission cannot access reports dashboard', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/dashboard', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(403);
    });

    test('users without read-all-reports permission cannot access reports transactions', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/transactions', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(403);
    });

    test('users without read-all-reports permission cannot export reports', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reports/transactions/export', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]);

        $response->assertStatus(403);
    });

    test('users without read-all-reports permission cannot access multiple report endpoints', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        // Test multiple endpoints
        $endpoints = [
            '/api/reports/transactions',
            '/api/reports/customers',
            '/api/reports/products/top-selling',
            '/api/reports/transactions/failed',
            '/api/reports/municipalities/export',
            '/api/reports/products/export',
            '/api/reports/categories/export',
            '/api/reports/customers/export',
            '/api/reports/orders/export'
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson($endpoint, [
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31'
                ]);

            expect($response->getStatusCode())->toBe(403, "Endpoint {$endpoint} should return 403 for users without read-all-reports permission");
        }
    });

    test('unauthenticated users cannot access reports', function () {
        $response = $this->postJson('/api/reports/dashboard', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ]);

        $response->assertStatus(401);
    });
});

describe('read-all-permissions permission', function () {
    test('users with read-all-permissions permission can access permissions index', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-permissions');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/permissions');

        $response->assertStatus(200);
    });

    test('users without read-all-permissions permission cannot access permissions index', function () {
        $user = User::factory()->create();
        // No se asigna el permiso

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/permissions');

        $response->assertStatus(403);
    });

    test('unauthenticated users cannot access permissions index', function () {
        $response = $this->getJson('/api/permissions');

        $response->assertStatus(401);
    });
});