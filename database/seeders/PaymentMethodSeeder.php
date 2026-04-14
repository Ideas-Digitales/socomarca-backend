<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = ['Transbank', 'Paypal', 'Stripe', 'Servipag', 'MercadoPago', 'Credito'];

        foreach ($methods as $name) {
            PaymentMethod::factory()->create([
                'name' => $name,
                'active' => true,
            ]);
        }
    }
}
