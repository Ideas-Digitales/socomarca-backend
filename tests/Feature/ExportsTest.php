<?php

use App\Exports\OrdersExport;
use App\Exports\TopMunicipalitiesExport;
use App\Exports\TopProductsExport;
use App\Models\Category;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Scenarios\ReportsScenario;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

describe('Reports Export Endpoints', function () {

    describe('Transactions Export', function () {

        it('puede exportar transacciones exitosas a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            // Crea 2 órdenes exitosas y 1 fallida
            Order::factory()->count(2)->create(['status' => 'completed']);
            Order::factory()->count(1)->create(['status' => 'failed']);

            postJson(route('reports.transactions.export'), ['filename' => 'export.xlsx']);

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

        it('puede exportar transacciones fallidas a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Order::factory()->count(2)->create(['status' => 'failed']);
            Order::factory()->count(1)->create(['status' => 'completed']);

            postJson(route('reports.transactions.export'), [
                'status' => 'failed',
                'filename' => 'export.xlsx'
            ]);

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

    });

    describe('Municipalities Export', function () {

        it('puede exportar top de comunas a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Municipality::factory()->count(3)->create();

            postJson(route('reports.municipalities.export'), [
                'filename' => 'top_municipalities.xlsx'
            ]);

            Excel::assertDownloaded('top_municipalities.xlsx', function ($export) {
                expect($export)->toBeInstanceOf(TopMunicipalitiesExport::class);

                return true;
            });
        });

    });

    describe('Products Export', function () {

        it('puede exportar top de productos a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Product::factory()->count(3)->create();

            postJson(route('reports.products.export') . '?aggregate=sales', [
                'filename' => 'top_products.xlsx'
            ]);

            Excel::assertDownloaded('top_products.xlsx', function ($export) {
                expect($export)->toBeInstanceOf(TopProductsExport::class);

                return true;
            });
        });

    });

    describe('Categories Export', function () {

        it('puede exportar categorías usando el endpoint de reportes a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Category::factory()->count(3)->create();

            postJson(route('reports.categories.export'), [
                'filename' => 'categories.xlsx'
            ]);

            Excel::assertDownloaded('categories.xlsx');
        });

    });

    describe('Customers Export', function () {

        it('puede exportar clientes usando el endpoint de reportes a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            $clientes = App\Models\User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            postJson(route('reports.customers.export'), [
                'filename' => 'customers.xlsx'
            ]);

            Excel::assertDownloaded('customers.xlsx');
        });

    });

    describe('Orders Export', function () {

        it('puede exportar órdenes a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Order::factory()->count(3)->create();

            $expectedFileName = 'Reporte_ordenes_' . now()->format('Ymd') . '.xlsx';

            postJson(route('reports.orders.export'));

            Excel::assertDownloaded($expectedFileName);
        });

    });

});

describe('Reports Data Endpoints', function () {

    describe('Dashboard', function () {

        it('puede obtener datos del dashboard de reportes', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            $response = postJson(route('reports.dashboard'));

            $response->assertStatus(200);
        });

    });

    describe('Products Data', function () {

        it('puede obtener lista de productos más vendidos', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Product::factory()->count(3)->create();

            $response = postJson(route('reports.products.top-selling'));

            $response->assertStatus(200);
        });

    });

    describe('Transactions Data', function () {

        it('puede obtener lista de transacciones', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Order::factory()->count(3)->create();

            $response = postJson(route('reports.transactions'));

            $response->assertStatus(200);
        });

        it('puede obtener lista de transacciones fallidas', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Order::factory()->count(2)->create(['status' => 'failed']);
            Order::factory()->count(1)->create(['status' => 'completed']);

            $response = postJson(route('reports.transactions.failed'));

            $response->assertStatus(200);
        });

        it('puede obtener una transacción específica por ID', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            $order = Order::factory()->create();

            $response = getJson(route('reports.transactions.show', $order->id));

            $response->assertStatus(200);
        });

    });

    describe('Customers Data', function () {

        it('puede obtener lista de clientes en reportes', function () {
            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            $clientes = App\Models\User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            $response = postJson(route('reports.customers'));

            $response->assertStatus(200);
        });

    });

});

describe('Legacy Export Endpoints', function () {

    describe('Authorization', function () {

        it('no puede exportar categorías si no tiene rol permitido', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->userWithoutPermission, ['api-access']);

            $response = getJson('/api/categories/exports');

            $response->assertStatus(403);
        });

    });

    describe('Categories Export (Legacy)', function () {

        it('puede exportar categorías a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            Category::factory()->count(3)->create();

            $response = getJson('/api/categories/exports');

            $response->assertStatus(200);
        });

    });

    describe('Users Export (Legacy)', function () {

        it('puede exportar clientes a excel', function () {
            Excel::fake();

            $scenario = ReportsScenario::make();
            Sanctum::actingAs($scenario->admin, ['api-access']);

            $clientes = App\Models\User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            $response = getJson('/api/users/exports');

            $response->assertStatus(200);
        });

    });

});
