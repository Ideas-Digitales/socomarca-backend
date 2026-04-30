<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RandomDocument>
 */
class RandomDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tidos = ['NVV', 'FCV', 'OCC'];
        $currencies = ['CLP'];
        return [
            'type' => fake()->randomElement($tidos),
            'document' => [
                'numero' => '0000000001',
                'tido' => fake()->randomElement($tidos),
                'idmaeedo' => random_int(1000000, 10000000),
                'uidxmaeedo' => fake()->uuid(),
                'vabrdo' => random_int(1000, 10000),
                'moneda' => fake()->randomElement($currencies),
                'estado' => [
                    'codigo' => '1',
                    'mensaje' => 'Grabación exitosa'
                ]
            ]

        ];
    }
}
