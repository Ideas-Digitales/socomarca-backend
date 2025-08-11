<?php

describe('Prices extremes read endpoint', function () {
    it('should return a forbidden response when not having \'read-all-prices\' permission', function () {
        $user = \App\Models\User::factory()->create();
        $this
            ->actingAs($user, 'sanctum')
            ->getJson(route('products.price-extremes'))
            ->assertForbidden();
    });

    it('should return a response with lower and higher active prices when having \'read-all-prices\' permission', function () {
        // Crear productos con precios específicos para validar los extremos
        $lowestPrice = \App\Models\Price::factory([
            'is_active' => true,
            'price' => 1000
        ])->create();

        $middlePrice = \App\Models\Price::factory([
            'is_active' => true,
            'price' => 5000
        ])->create();

        $highestPrice = \App\Models\Price::factory([
            'is_active' => true,
            'price' => 10000
        ])->create();

        // Crear algunos precios inactivos que no deberían afectar el resultado
        \App\Models\Price::factory([
            'is_active' => false,
            'price' => 500
        ])->create();

        \App\Models\Price::factory([
            'is_active' => false,
            'price' => 15000
        ])->create();

        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-prices');

        $response = $this
            ->actingAs($user, 'sanctum')
            ->getJson(route('products.price-extremes'))
            ->assertOk()
            ->assertJsonStructure([
                'lowest_price_product',
                'highest_price_product',
            ]);

        // Validar que los precios extremos son los correctos
        expect($response->json('lowest_price_product'))->toBe(1000);
        expect($response->json('highest_price_product'))->toBe(10000);
    });
});
