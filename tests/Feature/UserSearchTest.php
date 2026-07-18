<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\UserSearchScenario;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('should require authentication', function () {
    $response = getJson('/api/users');
    $response->assertStatus(401);
});

it('should require user search permissions', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->userWithoutPermissions, ['api-access']);
    postJson('/api/users/search')->assertStatus(403);
});

it('should allow search using "manage-users" permission', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();
    User::factory()->count(3)->create();

    postJson('/api/users/search')
        ->assertStatus(200)
        ->assertJsonStructure($scenario->listJsonStructure);
});

it('should filter users by exact name', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['name' => 'Juan Pérez']);
    User::factory()->create(['name' => 'María González']);
    User::factory()->create(['name' => 'Carlos López']);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'name',
                    'operator' => '=',
                    'value' => 'Juan Pérez',
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data')[0]['name'])->toBe('Juan Pérez');
    $response->assertStatus(200);
});

it('should filter users by partial name', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['name' => 'Juan Pérez']);
    User::factory()->create(['name' => 'Juana Martínez']);
    User::factory()->create(['name' => 'María González']);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'name',
                    'operator' => 'ILIKE',
                    'value' => '%juan%',
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $user) {
        expect(stripos($user['name'], 'juan'))->not->toBeFalse();
    }
    $response->assertStatus(200);
});

it('should filter users by email', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['email' => 'juan@empresa.com']);
    User::factory()->create(['email' => 'maria@empresa.com']);
    User::factory()->create(['email' => 'carlos@otrodominio.com']);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'email',
                    'operator' => 'ILIKE',
                    'value' => '%empresa%',
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $user) {
        expect(stripos($user['email'], 'empresa'))->not->toBeFalse();
    }
    $response->assertStatus(200);
});

it('should filter users by active status', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['is_active' => true]);
    User::factory()->create(['is_active' => false]);
    User::factory()->create(['is_active' => true]);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'is_active',
                    'operator' => '=',
                    'value' => true,
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $user) {
        expect($user['is_active'])->toBeTrue();
    }
    $response->assertStatus(200);
});

it('should sort users by name', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['name' => 'Zebra García']);
    User::factory()->create(['name' => 'Ana López']);
    User::factory()->create(['name' => 'Beta Martínez']);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'name',
                    'operator' => 'ILIKE',
                    'value' => '%',
                    'sort' => 'ASC'
                ]
            ]
        ]);

    $data = $response->json('data');
    expect($data)->toHaveCount(3);
    expect($data[0]['name'])->toBe('Ana López');
    expect($data[1]['name'])->toBe('Beta Martínez');
    expect($data[2]['name'])->toBe('Zebra García');
    $response->assertStatus(200);
});

it('filter users by role', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['rut' => '12345678-9']);
    User::factory()->create(['rut' => '98765432-1']);
    User::factory()->create(['rut' => '12000000-0']);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'rut',
                    'operator' => 'ILIKE',
                    'value' => '12%',
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $user) {
        expect(str_starts_with($user['rut'], '12'))->toBeTrue();
    }
    $response->assertStatus(200);
});

it('should be able to combine multiple filters', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    User::factory()->create(['name' => 'Juan Pérez', 'is_active' => true]);
    User::factory()->create(['name' => 'Juan García', 'is_active' => false]);
    User::factory()->create(['name' => 'María López', 'is_active' => true]);

    $response = postJson('/api/users/search', [
            'filters' => [
                [
                    'field' => 'name',
                    'operator' => 'ILIKE',
                    'value' => '%juan%'
                ],
                [
                    'field' => 'is_active',
                    'operator' => '=',
                    'value' => true
                ]
            ]
        ]);

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data')[0]['name'])->toBe('Juan Pérez');
    expect($response->json('data')[0]['is_active'])->toBeTrue();
    $response->assertStatus(200);
});

it('should filters users by roles', function () {
    \App\Models\User::truncate();

    $admin = User::factory()->create(['name' => 'Ana Admin']);
    $admin->assignRole('admin');

    $customer = User::factory()->create(['name' => 'Carlos Cliente']);
    $customer->assignRole('customer');

    Sanctum::actingAs($admin, ['api-access']);
    $response = postJson('/api/users/search', [
            'roles' => ['admin', 'customer'],
            'sort_field' => 'name',
            'sort_direction' => 'asc'
        ]);

    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect(collect($data)->pluck('name'))->toContain('Ana Admin');
    expect(collect($data)->pluck('name'))->toContain('Carlos Cliente');

    $response->assertStatus(200);
});

it('should sort users by name (asc) and id (desc)', function () {
    $scenario = UserSearchScenario::make();
    Sanctum::actingAs($scenario->adminUser, ['api-access']);
    User::truncate();

    $juan = User::factory()->create(['name' => 'Juan Pérez']);
    $ana = User::factory()->create(['name' => 'Ana López']);
    $carlos = User::factory()->create(['name' => 'Carlos Gómez']);

    // Ordenar por nombre ascendente
    $responseAsc = postJson('/api/users/search', [
            'sort_field' => 'name',
            'sort_direction' => 'asc'
        ]);
    $namesAsc = array_column($responseAsc->json('data'), 'name');
    expect($namesAsc)->toBe(['Ana López', 'Carlos Gómez', 'Juan Pérez']);

    // Ordenar por id descendente
    $responseDesc = postJson('/api/users/search', [
            'sort_field' => 'id',
            'sort_direction' => 'desc'
        ]);
    $idsDesc = array_column($responseDesc->json('data'), 'id');
    $expectedDesc = collect([$juan, $ana, $carlos])->sortByDesc('id')->pluck('id')->values()->all();
    expect($idsDesc)->toBe($expectedDesc);
});
