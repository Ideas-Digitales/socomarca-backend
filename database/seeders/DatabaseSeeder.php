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
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            $this->call([
                RegionSeeder::class,
                RolesAndPermissionsSeeder::class,
                UserSeeder::class,
                AddressSeeder::class,
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
                PaymentMethodSeeder::class,
                RolesAndPermissionsSeeder::class,
                SiteInfoSeeder::class,
            ]);
        }
    }

}
