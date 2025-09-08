<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {

        if (app()->environment(['local', 'qa','testing'])) {
            
            $this->call([
                RegionSeeder::class,
                RolesAndPermissionsSeeder::class,
                UserSeeder::class,
                AddressSeeder::class,
                WarehouseSeeder::class,
                ProductSeeder::class,
                PaymentMethodSeeder::class,
                SiteInfoSeeder::class,
                OrderSeeder::class,
                CartItemSeeder::class,
                FavoriteSeeder::class,
                FaqSeeder::class,
            ]);

        }else{
            $this->call([
                RegionSeeder::class,
                WarehouseSeeder::class,
                PaymentMethodSeeder::class,
                RolesAndPermissionsSeeder::class,
                SiteInfoSeeder::class,
            ]);
        }
    }

}
