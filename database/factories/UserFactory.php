<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numberBetween(777777777, 999999999),
            'rut' => $rut,
            'business_name' => fake()->company(),
            'is_active' => fake()->boolean(),
            'last_login' => fake()->dateTimeThisYear(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Calculate the verification digit for a Chilean RUT.
     *
     * @param int $rut
     * @return string
     */
    private function calculateDv(int $rut): string
    {
        $s = 1;
        $m = 0;

        for (; $rut != 0; $rut /= 10) {
            $s = ($s + $rut % 10 * (9 - $m++ % 6)) % 11;
        }

        return $s ? (string)($s - 1) : 'K';
    }
}
