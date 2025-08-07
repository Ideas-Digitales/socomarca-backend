<?php
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'superadmin']);
    Role::firstOrCreate(['name' => 'customer']);
    Role::firstOrCreate(['name' => 'supervisor']);
    Role::firstOrCreate(['name' => 'editor']);
    
    // Crear algunos permisos para testing
    Permission::firstOrCreate(['name' => 'see-all-reports']);
    Permission::firstOrCreate(['name' => 'manage-users']);
    Permission::firstOrCreate(['name' => 'see-own-purchases']);
});

describe('GET /api/roles authorization', function () {
    test('admin users can access roles index', function () {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
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
    });

    test('superadmin users can access roles index', function () {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $response = $this->actingAs($superadmin, 'sanctum')
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
    });

    test('non-admin users cannot access roles index', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(403);
    });

    test('supervisor users cannot access roles index', function () {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(403);
    });

    test('editor users cannot access roles index', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $response = $this->actingAs($editor, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(403);
    });

    test('unauthenticated users cannot access roles index', function () {
        $response = $this->getJson('/api/roles');

        $response->assertStatus(401);
    });

    test('roles endpoint returns correct data structure', function () {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Asignar algunos permisos a un rol para verificar la estructura
        $role = Role::findByName('admin');
        $role->givePermissionTo(['see-all-reports', 'manage-users']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(200);

        $data = $response->json();
        
        // Verificar que es un array
        expect($data)->toBeArray();
        
        // Buscar el rol admin en la respuesta
        $adminRole = collect($data)->firstWhere('name', 'admin');
        
        expect($adminRole)->not->toBeNull();
        expect($adminRole['id'])->toBeInt();
        expect($adminRole['name'])->toBe('admin');
        expect($adminRole['permissions'])->toBeArray();
        expect($adminRole['permissions'])->toContain('see-all-reports');
        expect($adminRole['permissions'])->toContain('manage-users');
        expect($adminRole['created_at'])->toBeString();
        expect($adminRole['updated_at'])->toBeString();
    });
});

describe('GET /api/roles/users authorization', function () {
    test('admin users can access roles with users', function () {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'role',
                    'users' => [
                        '*' => ['id', 'name', 'email']
                    ]
                ]
            ]);
    });

    test('superadmin users can access roles with users', function () {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $response = $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'role',
                    'users' => [
                        '*' => ['id', 'name', 'email']
                    ]
                ]
            ]);
    });

    test('non-admin/superadmin users cannot access roles with users', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(403);
    });

    test('supervisor users cannot access roles with users', function () {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(403);
    });

    test('editor users cannot access roles with users', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $response = $this->actingAs($editor, 'sanctum')
            ->getJson('/api/roles/users');

        $response->assertStatus(403);
    });

    test('unauthenticated users cannot access roles with users', function () {
        $response = $this->getJson('/api/roles/users');

        $response->assertStatus(401);
    });
});

describe('GET /api/roles/{user} authorization', function () {
    test('admin users can get user roles', function () {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user_id',
                'user_name',
                'roles',
                'permissions'
            ])
            ->assertJson([
                'user_id' => $targetUser->id,
                'user_name' => $targetUser->name
            ]);
    });

    test('superadmin users can get user roles', function () {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $targetUser = User::factory()->create();
        $targetUser->assignRole('customer');

        $response = $this->actingAs($superadmin, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user_id',
                'user_name',
                'roles',
                'permissions'
            ])
            ->assertJson([
                'user_id' => $targetUser->id,
                'user_name' => $targetUser->name
            ]);
    });

    test('non-admin/superadmin users cannot get user roles', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $targetUser = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(403);
    });

    test('supervisor users cannot get user roles', function () {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $targetUser = User::factory()->create();

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(403);
    });

    test('editor users cannot get user roles', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $targetUser = User::factory()->create();

        $response = $this->actingAs($editor, 'sanctum')
            ->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(403);
    });

    test('unauthenticated users cannot get user roles', function () {
        $targetUser = User::factory()->create();

        $response = $this->getJson("/api/roles/{$targetUser->id}");

        $response->assertStatus(401);
    });

    test('returns 404 for non-existent user', function () {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/roles/99999');

        $response->assertStatus(404);
    });
});