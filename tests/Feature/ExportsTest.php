<?php

use App\Exports\OrdersExport;
use App\Exports\TopMunicipalitiesExport;
use App\Exports\TopProductsExport;
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

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

describe('Reports Export Endpoints', function () {
    
    describe('Transactions Export', function () {
        
        it('puede exportar transacciones exitosas a excel', function () {
            Excel::fake();

            // Prepara un usuario admin
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            // Crea 2 órdenes exitosas y 1 fallida
            Order::factory()->count(2)->create(['status' => 'completed']);
            Order::factory()->count(1)->create(['status' => 'failed']);

            // Ejecuta la exportación autenticado como admin
            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.transactions.export'), ['filename' => 'export.xlsx']);

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

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Order::factory()->count(2)->create(['status' => 'failed']);
            Order::factory()->count(1)->create(['status' => 'completed']);

            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.transactions.export'), [
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

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Municipality::factory()->count(3)->create();

            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.municipalities.export'), [
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

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Product::factory()->count(3)->create();

            $this->actingAs($admin, 'sanctum')
                 ->post(route('reports.products.export') . '?aggregate=sales', [
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

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Category::factory()->count(3)->create();

            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.categories.export'), [
                    'filename' => 'categories.xlsx'
                ]);

            Excel::assertDownloaded('categories.xlsx');
        });
        
    });

    describe('Customers Export', function () {
        
        it('puede exportar clientes usando el endpoint de reportes a excel', function () {
            Excel::fake();

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            $clientes = User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.customers.export'), [
                    'filename' => 'customers.xlsx'
                ]);

            Excel::assertDownloaded('customers.xlsx');
        });
        
    });

    describe('Orders Export', function () {
        
        it('puede exportar órdenes a excel', function () {
            Excel::fake();

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Order::factory()->count(3)->create();

            $expectedFileName = 'Reporte_ordenes_' . now()->format('Ymd') . '.xlsx';
            
            $this->actingAs($admin, 'sanctum')
                ->post(route('reports.orders.export'));

            Excel::assertDownloaded($expectedFileName);
        });
        
    });
    
});

describe('Reports Data Endpoints', function () {
    
    describe('Dashboard', function () {
        
        it('puede obtener datos del dashboard de reportes', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('reports.dashboard'));

            $response->assertStatus(200);
        });
        
    });

    describe('Products Data', function () {
        
        it('puede obtener lista de productos más vendidos', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Product::factory()->count(3)->create();

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('reports.products.top-selling'));

            $response->assertStatus(200);
        });
        
    });

    describe('Transactions Data', function () {
        
        it('puede obtener lista de transacciones', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Order::factory()->count(3)->create();

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('reports.transactions'));

            $response->assertStatus(200);
        });

        it('puede obtener lista de transacciones fallidas', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Order::factory()->count(2)->create(['status' => 'failed']);
            Order::factory()->count(1)->create(['status' => 'completed']);

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('reports.transactions.failed'));

            $response->assertStatus(200);
        });

        it('puede obtener una transacción específica por ID', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            $order = Order::factory()->create();

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->get(route('reports.transactions.show', $order->id));

            $response->assertStatus(200);
        });
        
    });

    describe('Customers Data', function () {
        
        it('puede obtener lista de clientes en reportes', function () {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            $clientes = User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            $response = $this->actingAs($admin, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('reports.customers'));

            $response->assertStatus(200);
        });
        
    });
    
});

describe('Legacy Export Endpoints', function () {
    
    describe('Authorization', function () {
        
        it('no puede exportar categorías si no tiene rol permitido', function () {
            Excel::fake();

            $user = User::factory()->create(); // Sin rol admin/superadmin/supervisor

            $response = $this->actingAs($user, 'sanctum')
                ->get('/api/categories/exports');

            $response->assertStatus(403);
        });
        
    });

    describe('Categories Export', function () {
        
        it('puede exportar categorías a excel', function () {
            Excel::fake();

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            Category::factory()->count(3)->create();

            $response = $this->actingAs($admin, 'sanctum')
                ->get('/api/categories/exports');

            $response->assertStatus(200);
        });
        
    });

    describe('Users Export', function () {
        
        it('puede exportar clientes a excel', function () {
            Excel::fake();

            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $admin->givePermissionTo('read-all-reports');

            $clientes = User::factory()->count(3)->create();
            foreach ($clientes as $cliente) {
                $cliente->assignRole('customer');
            }

            $response = $this->actingAs($admin, 'sanctum')
                ->get('/api/users/exports');

            $response->assertStatus(200);
        });
        
    });
    
});