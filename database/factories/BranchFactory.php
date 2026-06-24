<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rut = fake()->numberBetween(10000000, 25000000);
        $rut .= '-' . $this->calculateDv($rut);

        return [
            'name'             => fake()->company(),
            'code'             => fake()->unique()->regexify('[A-Z0-9]{6}'),
            'user_code'        => $rut,
            'email'            => fake()->unique()->safeEmail(),
            'commercial_email' => fake()->unique()->safeEmail(),
            'phone'            => (string) fake()->numberBetween(777777777, 999999999),
            'rut'              => $rut,
            'business_name'    => fake()->company(),
            'user_id'          => User::factory(),
        ];
    }

    /**
     * Calculate the verification digit for a Chilean RUT.
     */
    private function calculateDv(int $rut): string
    {
        $s = 1;
        $m = 0;

        for (; $rut != 0; $rut /= 10) {
            $s = ($s + $rut % 10 * (9 - $m++ % 6)) % 11;
        }

        return $s ? (string) ($s - 1) : 'K';
    }
}
