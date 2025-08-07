<?php
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Arr;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->givePermissionTo('see-all-reports');
    $this->admin = $admin;
});

test('puede filtrar ventas por monto mínimo y máximo', function () {
    Order::factory()->create(['amount' => 5000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 15000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 25000, 'status' => 'completed', 'created_at' => now()]);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
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

test('puede filtrar ventas por cliente', function () {
    $cliente = User::factory()->create(['name' => 'Cliente Uno']);
    $cliente->assignRole('customer');
    Order::factory()->create(['user_id' => $cliente->id, 'amount' => 10000, 'status' => 'completed', 'created_at' => now()]);
    Order::factory()->create(['amount' => 20000, 'status' => 'completed', 'created_at' => now()]);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
        'type' => 'sales',
        'client' => 'Cliente Uno',
    ]);

    $response->assertStatus(200);
    $clientes = Arr::get($response->json(), 'customers', []);
    expect($clientes)->toContain('Cliente Uno');
});

test('puede obtener top municipalidades con filtro de monto', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
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

test('puede obtener top productos con filtro de monto', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
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

test('puede obtener top categorías con filtro de monto', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
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

test('puede obtener top clientes', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
        'type' => 'top-customers',
    ]);
    $response->assertStatus(200);
    $data = $response->json('top_customers');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['customer', 'total', 'last_purchase']);
    }
});

test('puede obtener revenue', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
        'type' => 'revenue',
    ]);
    $response->assertStatus(200);
    $data = $response->json('revenues');
    expect($data)->toBeArray();
    foreach ($data as $item) {
        expect($item)->toHaveKeys(['month', 'revenue']);
    }
});

test('puede obtener transacciones', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
        'type' => 'transactions',
    ]);
    $response->assertStatus(200);
    $data = $response->json('chart');
    expect($data)->toBeArray();
});

test('puede obtener transacciones fallidas con filtro de monto', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
        'type' => 'transactions-failed',
        'total_min' => 1000,
        'total_max' => 100000,
    ]);
    $response->assertStatus(200);
    $data = $response->json('chart');
    expect($data)->toBeArray();
});

test('puede filtrar ventas por todos los filtros opcionales', function () {
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

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/orders/reports', [
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