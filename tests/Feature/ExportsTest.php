<?php

use App\Exports\OrdersExport;
use App\Exports\TopMunicipalitiesExport;
use App\Exports\ProductsExport;
use App\Models\User;
use App\Models\Category;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

// Test exportación de categorías
test('puede exportar categorías a excel', function () {
    Excel::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Category::factory()->count(3)->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->get('/api/categories/exports');

    $response->assertStatus(200);

});

// Test exportación de clientes
test('puede exportar clientes a excel', function () {
    Excel::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $clientes = User::factory()->count(3)->create();
    foreach ($clientes as $cliente) {
        $cliente->assignRole('customer');
    }

    $response = $this->actingAs($admin, 'sanctum')
        ->get('/api/users/exports');

    $response->assertStatus(200);

});

// Test que no permite exportar categorías si el usuario no tiene rol permitido
test('no puede exportar categorías si no tiene rol permitido', function () {
    Excel::fake();

    $user = User::factory()->create(); // Sin rol admin/superadmin/supervisor

    $response = $this->actingAs($user, 'sanctum')
        ->get('/api/categories/exports');

    $response->assertStatus(403);
});

test('puede exportar transacciones exitosas a excel', function () {
    Excel::fake();

    // Prepara un usuario admin
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Crea 2 órdenes exitosas y 1 fallida
    Order::factory()->count(2)->create(['status' => 'completed']);
    Order::factory()->count(1)->create(['status' => 'failed']);

    // Ejecuta la exportación autenticado como admin
    $response = $this->actingAs($admin, 'sanctum')
        ->post('/api/orders/reports/transactions/export', ['filename' => 'export.xlsx']);

    $response->assertStatus(200);

    Excel::assertDownloaded('export.xlsx', function ($export) {
                
        expect($export)->toBeInstanceOf(OrdersExport::class);

        $collection = $export->collection();
        expect($collection)->toHaveCount(2);
        foreach ($collection as $order) {
            expect($order['Estado'] ?? $order->status)->toBe('completed');
        }

        return true;
    });
});

test('puede exportar transacciones fallidas a excel', function () {
    Excel::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Order::factory()->count(2)->create(['status' => 'failed']);
    Order::factory()->count(1)->create(['status' => 'completed']);

    $response = $this->actingAs($admin, 'sanctum')
        ->post('/api/orders/reports/transactions/export', [
            'status' => 'failed',
            'filename' => 'export.xlsx'
        ]);

    $response->assertStatus(200);

    Excel::assertDownloaded('export.xlsx', function ($export) {
        expect($export)->toBeInstanceOf(OrdersExport::class);
        $collection = $export->collection();
        expect($collection)->toHaveCount(2);
        foreach ($collection as $order) {
            expect($order['Estado'] ?? $order->status)->toBe('failed');
        }

        return true;
    });
});

// Test para exportar top de comunas
test('puede exportar top de comunas a excel', function () {
    Excel::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Municipality::factory()->count(3)->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->post('/api/orders/reports/municipalities/export', [
            'filename' => 'top_municipalities.xlsx'
        ]);

    $response->assertStatus(200);

    Excel::assertDownloaded('top_municipalities.xlsx', function ($export) {
        expect($export)->toBeInstanceOf(TopMunicipalitiesExport::class);

        return true;
    });
});

// Test para exportar top de productos
test('puede exportar top de productos a excel', function () {
    Excel::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Product::factory()->count(3)->create();

    $response = $this->actingAs($admin, 'sanctum')
         ->post('/api/orders/reports/products/export', [
            'filename' => 'top_products.xlsx'
        ]);

    $response->assertStatus(200);

    Excel::assertDownloaded('top_products.xlsx', function ($export) {
        expect($export)->toBeInstanceOf(ProductsExport::class);

        return true;
    });
});
