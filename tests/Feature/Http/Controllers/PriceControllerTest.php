<?php

describe('Prices read endpoint', function () {
    it('should respond forbidden when not having \'read-all-prices\' permission', function () {
        $user = \App\Models\User::factory()->create();
        $this
            ->actingAs($user, 'sanctum')
            ->getJson(route('prices.index'))
            ->assertForbidden();
    });

    it('should respond the full prices list when having \'read-all-prices\' permission', function () {
        // Crear precios activos
        $prices = \App\Models\Price::factory([
            'is_active' => true
        ])->count(3)->create();

        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-prices');

        $response = $this
            ->actingAs($user, 'sanctum')
            ->getJson(route('prices.index'))
            ->assertOk()
            ->assertJsonStructure([
                [
                    "id",
                    "product_id",
                    "random_product_id",
                    "price_list_id",
                    "unit",
                    "price",
                    "valid_from",
                    "valid_to",
                    "is_active",
                    "created_at",
                    "updated_at",
                    "stock",
                    "min_price_quantity",
                    "max_price_quantity",
                ]
            ]);

        $responseData = $response->json();

        // Verificar que la cantidad de precios retornados coincida con los precios activos
        expect(count($responseData))->toBe($prices->count());

        // Verificar que todos los IDs en la respuesta correspondan a precios activos en la BD
        $responseIds = collect($responseData)->pluck('id')->sort()->values();
        $pricesIds = $prices->pluck('id')->sort()->values();
        expect($responseIds)->each(fn ($id) => $id->toBeIn($pricesIds));
    });

});
