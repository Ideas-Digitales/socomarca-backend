<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouses = [
            [
                'business_code' => '01',
                'branch_code' => 'MAIN',
                'warehouse_code' => 'MAIN',
                'name' => 'Bodega Principal',
                'address' => 'Dirección Principal',
                'phone' => '+56912345678',
                'priority' => 1,
                'is_active' => true,
            ],
            [
                'business_code' => '01',
                'branch_code' => 'SEC1',
                'warehouse_code' => 'SEC1',
                'name' => 'Bodega Secundaria 1',
                'address' => 'Dirección Secundaria 1',
                'phone' => '+56912345679',
                'priority' => 2,
                'is_active' => true,
            ],
            [
                'business_code' => '01',
                'branch_code' => 'SEC2',
                'warehouse_code' => 'SEC2',
                'name' => 'Bodega Secundaria 2',
                'address' => 'Dirección Secundaria 2',
                'phone' => '+56912345680',
                'priority' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($warehouses as $warehouseData) {
            Warehouse::firstOrCreate(
                ['warehouse_code' => $warehouseData['warehouse_code']],
                $warehouseData
            );
        }
    }
}
