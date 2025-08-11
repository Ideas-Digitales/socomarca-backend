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
    
    // Crear permisos relevantes para testing
    Permission::firstOrCreate(['name' => 'read-all-roles']);
    Permission::firstOrCreate(['name' => 'read-user-roles']);
    Permission::firstOrCreate(['name' => 'see-all-reports']);
    Permission::firstOrCreate(['name' => 'manage-users']);
    Permission::firstOrCreate(['name' => 'see-own-purchases']);
    
    // Asignar permisos a roles
    $superadmin = Role::findByName('superadmin');
    $admin = Role::findByName('admin');
    
    $superadmin->givePermissionTo(['read-all-roles', 'read-user-roles']);
    $admin->givePermissionTo(['read-user-roles']);
});

// Tests para ruta GET /api/roles - Requiere permiso 'read-all-roles'
test('superadmin can access roles index with read-all-roles permission', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $response = $this->actingAs($superadmin, 'sanctum')
        ->getJson('/api/roles');

    $response->assertStatus(200);
});

test('other users cannot list roles and permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('customer'); 

    $route = '/api/roles/users';

    $this->actingAs($user, 'sanctum')
        ->getJson($route)
        ->assertStatus(403); 
});

test('admin cannot access roles index without read-all-roles permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/roles');

    $response->assertStatus(403);
});

test('supervisor cannot access roles index', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/roles');

    $response->assertStatus(403);
});

test('editor cannot access roles index', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $response = $this->actingAs($editor, 'sanctum')
        ->getJson('/api/roles');

    $response->assertStatus(403);
});

test('customer cannot access roles index', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/roles');

    $response->assertStatus(403);
});

test('unauthenticated user cannot access roles index', function () {
    $response = $this->getJson('/api/roles');

    $response->assertStatus(401);
});

// Tests para ruta GET /api/roles/users - Requiere permiso 'read-user-roles'
test('superadmin can access roles with users with read-user-roles permission', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $response = $this->actingAs($superadmin, 'sanctum')
        ->getJson('/api/roles/users');

    $response->assertStatus(200);
});

test('admin can access roles with users with read-user-roles permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/roles/users');

    $response->assertStatus(200);
});

test('supervisor cannot access roles with users without permission', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/roles/users');

    $response->assertStatus(403);
});

test('editor cannot access roles with users without permission', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $response = $this->actingAs($editor, 'sanctum')
        ->getJson('/api/roles/users');

    $response->assertStatus(403);
});

test('customer cannot access roles with users without permission', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/roles/users');

    $response->assertStatus(403);
});

test('unauthenticated user cannot access roles with users', function () {
    $response = $this->getJson('/api/roles/users');

    $response->assertStatus(401);
});

// Tests para ruta GET /api/roles/{user} - Requiere permiso 'read-user-roles'
test('superadmin can access user roles with read-user-roles permission', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');
    
    $targetUser = User::factory()->create();
    $targetUser->assignRole('customer');

    $response = $this->actingAs($superadmin, 'sanctum')
        ->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(200);
});

test('admin can access user roles with read-user-roles permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    
    $targetUser = User::factory()->create();
    $targetUser->assignRole('customer');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(200);
});

test('supervisor cannot access user roles without permission', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    
    $targetUser = User::factory()->create();
    $targetUser->assignRole('customer');

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(403);
});

test('editor cannot access user roles without permission', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    
    $targetUser = User::factory()->create();
    $targetUser->assignRole('customer');

    $response = $this->actingAs($editor, 'sanctum')
        ->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(403);
});

test('customer cannot access user roles without permission', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');
    
    $targetUser = User::factory()->create();
    $targetUser->assignRole('customer');

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(403);
});

test('unauthenticated user cannot access user roles', function () {
    $targetUser = User::factory()->create();
    
    $response = $this->getJson("/api/roles/{$targetUser->id}");

    $response->assertStatus(401);
});

// Tests de estructura de respuestas
test('roles index returns correct data structure', function () {
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

    // Verificar que devuelve al menos los roles básicos
    $data = $response->json();
    $roleNames = array_column($data, 'name');
    
    expect($roleNames)->toContain('admin');
    expect($roleNames)->toContain('customer');
    expect($roleNames)->toContain('superadmin');
    expect($roleNames)->toContain('supervisor');
    expect($roleNames)->toContain('editor');
});

test('roles with users returns correct data structure', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');
    
    // Crear usuario con rol para verificar estructura
    $testUser = User::factory()->create();
    $testUser->assignRole('customer');

    $response = $this->actingAs($superadmin, 'sanctum')
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

test('user roles returns correct data structure', function () {
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
        ->assertJsonPath('user_id', $targetUser->id)
        ->assertJsonPath('user_name', $targetUser->name);
        
    $data = $response->json();
    expect($data['roles'])->toBeArray();
    expect($data['permissions'])->toBeArray();
});

