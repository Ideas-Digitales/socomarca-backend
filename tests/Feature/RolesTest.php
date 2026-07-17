<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

describe('read-all-roles permission', function () {
    it('users with read-all-roles permission can access roles index', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-roles');
        Sanctum::actingAs($user, ['api-access']);

        $response = getJson(route('roles.index'));

        $response->assertStatus(200);
    });

    it('users without read-all-roles permission cannot access roles index', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);
        getJson(route('roles.index'))->assertStatus(403);
    });

    it('unauthenticated users cannot access roles index', function () {
        getJson(route('roles.index'))->assertStatus(401);
    });

    it('roles index returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-roles');
        Sanctum::actingAs($user, ['api-access']);

        // Asignar algunos permisos a un rol para verificar la estructura
        $role = Role::findByName('admin', 'web');
        $role->givePermissionTo(['read-all-reports']);

        $response = getJson(route('roles.index'));

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
    it('users with read-user-roles permission can access roles with users', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        Sanctum::actingAs($user, ['api-access']);

        $response = getJson(route('roles.users'));

        $response->assertStatus(200);
    });

    it('users without read-user-roles permission cannot access roles with users', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);
        getJson(route('roles.users'))->assertStatus(403);
    });

    it('users with read-user-roles permission can access specific user roles', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        Sanctum::actingAs($user, ['api-access']);

        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        getJson(route('roles.users', $targetUser->id))->assertStatus(200);
    });

    it('users without read-user-roles permission cannot access specific user roles', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        getJson(route('roles.users', $targetUser->id))->assertStatus(403);
    });

    it('roles with users returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        Sanctum::actingAs($user, ['api-access']);

        // Crear usuario con rol para verificar estructura
        $testUser = User::factory()->create();
        $testUser->assignRole('customer');

        $response = getJson(route('roles.users'));

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

    it('user roles returns correct data structure for users with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-user-roles');
        Sanctum::actingAs($user, ['api-access']);

        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = getJson(route('roles.show', ['user' => $targetUser->id]));

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

    it('unauthenticated users cannot access roles with users', function () {
        $response = getJson(route('roles.users'));
        $response->assertStatus(401);
    });

    it('unauthenticated users cannot access specific user roles', function () {
        $targetUser = User::factory()->create();
        getJson(route('roles.users', $targetUser->id))->assertStatus(401);
    });
});

describe('read-all-reports permission', function () {
    it('users with read-all-reports permission can access reports dashboard', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.dashboard'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(200);
    });

    it('users with read-all-reports permission can access reports transactions', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.transactions'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(200);
    });

    it('users with read-all-reports permission can export reports', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.transactions.export'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(200);
    });

    it('users without read-all-reports permission cannot access reports dashboard', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.dashboard'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(403);
    });

    it('users without read-all-reports permission cannot access reports transactions', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.transactions'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(403);
    });

    it('users without read-all-reports permission cannot export reports', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('reports.transactions.export'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ])->assertStatus(403);
    });

    it('users without read-all-reports permission cannot access multiple report endpoints', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $routes = [
            'reports.transactions',
            'reports.customers',
            'reports.products.top-selling',
            'reports.transactions.failed',
            'reports.municipalities.export',
            'reports.products.export',
            'reports.categories.export',
            'reports.customers.export',
            'reports.orders.export'
        ];

        foreach ($routes as $routeName) {
            $response = postJson(route($routeName), [
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31'
                ]);

            expect($response->getStatusCode())->toBe(403, "Route {$routeName} should return 403 for users without read-all-reports permission");
        }
    });

    it('unauthenticated users cannot access reports', function () {
        $response = postJson(route('reports.dashboard'), [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ]);

        $response->assertStatus(401);
    });
});

describe('read-all-permissions permission', function () {
    it('users with read-all-permissions permission can access permissions index', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-permissions');
        Sanctum::actingAs($user, ['api-access']);
        getJson(route('permissions.index'))->assertStatus(200);
    });

    it('users without read-all-permissions permission cannot access permissions index', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);
        getJson(route('permissions.index'))->assertStatus(403);
    });

    it('unauthenticated users cannot access permissions index', function () {
        getJson(route('permissions.index'))->assertStatus(401);
    });
});
