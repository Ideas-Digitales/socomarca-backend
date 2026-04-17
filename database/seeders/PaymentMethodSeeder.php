<?php

namespace Database\Seeders;

use App\Models\Payment;
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
        $methods = [
            'Transbank' => 'transbank',
            'Paypal' => 'paypal',
            'Stripe' => 'stripe',
            'Servipag' => 'servipag',
            'MercadoPago' => 'mercadopago',
            'Crédito Random' => 'random_credit',
        ];

        $methods = [
            'transbank' => 'Transbank',
            'paypal' => 'Paypal',
            'stripe' => 'Stripe',
            'servipag' => 'Servipag',
            'mercadopago' => 'MercadoPago',
            'random_credit' => 'Crédito Random',
        ];


        $existingCodes = PaymentMethod::whereNotNull('code')
            ->where('code', '!=', '')
            ->pluck('code')
            ->toArray();

        $missingCodes = array_diff(array_keys($methods), $existingCodes);

        $missingMethods = array_filter($methods, function ($code) use ($missingCodes) {
            return in_array($code, $missingCodes);
        }, ARRAY_FILTER_USE_KEY);

        $payload = [];
        foreach ($missingMethods as $code => $name) {
            $payload[] = [
                'name' => $name,
                'code' => $code,
            ];
        }

        PaymentMethod::upsert(
            $payload,
            ['code'],
            ['code', 'name']
        );
    }
}
