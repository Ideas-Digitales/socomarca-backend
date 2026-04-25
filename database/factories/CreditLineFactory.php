<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CreditLine>
 */
class CreditLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_code' => fake()->bothify('?##'),
            'user_id' => User::factory(),
            'state' => [
                'CRSD' => 47707007999999.99,
                'CRSDVU' => 5940894,
                'CRSDVV' => 1115408,
                'CRSDCU' => 0,
                'CRSDCV' => 0,
            ]
        ];
    }
}
