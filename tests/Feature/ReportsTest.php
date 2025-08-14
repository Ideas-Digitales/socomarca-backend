<?php
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Arr;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->givePermissionTo('read-all-reports');
    $this->admin = $admin;
});

describe('Reports Dashboard', function () {
    it('can filter sales by minimum and maximum amount', function () {
    Order::factory()->create(['amount' => 5000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 15000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 25000, 'status' => 'completed', 'created_at' => now()]);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'sales',
        'total_min' => 10000,
        'total_max' => 20000,
    ]);

    $response->assertStatus(200);
    $totals = Arr::get($response->json(), 'totals.0.sales_by_customer', []);
    foreach ($totals as $venta) {
        expect($venta['total'])->toBeGreaterThanOrEqual(10000)
            ->toBeLessThanOrEqual(20000);
    }
});

    it('can filter sales by customer', function () {
    $cliente = User::factory()->create(['name' => 'Cliente Uno']);
    $cliente->assignRole('customer');
    Order::factory()->create(['user_id' => $cliente->id, 'amount' => 10000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 20000, 'status' => 'completed', 'created_at' => now()]);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'sales',
        'client' => 'Cliente Uno',
    ]);

    $response->assertStatus(200);
    $clientes = Arr::get($response->json(), 'customers', []);
    expect($clientes)->toContain('Cliente Uno');
});

    it('can get top municipalities with amount filter', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'top-municipalities',
        'total_min' => 1000,
        'total_max' => 100000,
    ]);
    $response->assertStatus(200);
    $data = $response->json('top_municipalities');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['month', 'municipality', 'total', 'orders_count']);
        expect($item['total'])->toBeGreaterThanOrEqual(1000)
            ->toBeLessThanOrEqual(100000);
    }
});

    it('can get top products with amount filter', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'top-products',
        'total_min' => 1000,
        'total_max' => 100000,
    ]);
    $response->assertStatus(200);
    $data = $response->json('top_products');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['month', 'product', 'total']);
        expect($item['total'])->toBeGreaterThanOrEqual(1000)
            ->toBeLessThanOrEqual(100000);
    }
});

    it('can get top categories with amount filter', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'top-categories',
        'total_min' => 1000,
        'total_max' => 100000,
    ]);
    $response->assertStatus(200);
    $data = $response->json('top_categories');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['month', 'category', 'total']);
        expect($item['total'])->toBeGreaterThanOrEqual(1000)
            ->toBeLessThanOrEqual(100000);
    }
});

    it('can get top customers', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'top-customers',
    ]);
    $response->assertStatus(200);
    $data = $response->json('top_customers');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['customer', 'total', 'last_purchase']);
    }
});

    it('can get revenue', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'revenue',
    ]);
    $response->assertStatus(200);
    $data = $response->json('revenues');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['month', 'revenue']);
    }
});

    it('can get transactions', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'transactions',
    ]);
    $response->assertStatus(200);
    $data = $response->json('chart');
    expect($data)->toBeArray();
});

    it('can get failed transactions with amount filter', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'type' => 'transactions-failed',
        'total_min' => 1000,
        'total_max' => 100000,
    ]);
    $response->assertStatus(200);
    $data = $response->json('chart');
    expect($data)->toBeArray();
});

    it('can filter sales by all optional filters', function () {
    $cliente = User::factory()->create(['name' => 'Juan Perez']);
    $cliente->assignRole('customer');
    Order::factory()->create([
        'user_id' => $cliente->id,
        'amount' => 4000000,
        'status' => 'completed',
        'created_at' => '2025-03-15'
    ]);
    Order::factory()->create([
        'user_id' => $cliente->id,
        'amount' => 7000000,
        'status' => 'completed',
        'created_at' => '2025-04-10'
    ]);
    Order::factory()->create([
        'amount' => 5000000,
        'status' => 'completed',
        'created_at' => '2025-05-20'
    ]);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/dashboard', [
        'start' => '2025-01-01',
        'end' => '2025-06-30',
        'type' => 'sales',
        'total_min' => 3500000,
        'total_max' => 6000000,
        'client' => 'Juan Perez'
    ]);

    $response->assertStatus(200);
    $totals = \Illuminate\Support\Arr::get($response->json(), 'totals.0.sales_by_customer', []);
    foreach ($totals as $venta) {
        expect($venta['total'])->toBeGreaterThanOrEqual(3500000)
            ->toBeLessThanOrEqual(6000000);
        expect($venta['customer'])->toBe('Juan Perez');
    }
});

});

describe('Reports Dashboard - Permission Tests', function () {
    it('users with read-all-reports permission can access dashboard', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-reports');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/reports/dashboard', [
            'type' => 'sales',
        ]);

        $response->assertStatus(200);
    });

    it('users without read-all-reports permission cannot access dashboard', function () {
        $user = User::factory()->create();
        // No permission assigned

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/reports/dashboard', [
            'type' => 'sales',
        ]);

        $response->assertStatus(403);
    });

    it('unauthenticated users cannot access dashboard', function () {
        $response = $this->postJson('/api/reports/dashboard', [
            'type' => 'sales',
        ]);

        $response->assertStatus(401);
    });
});