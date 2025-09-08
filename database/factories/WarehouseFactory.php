<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_code' => '01',
            'branch_code' => $this->faker->randomElement(['MAIN', 'SEC1', 'SEC2']),
            'warehouse_code' => $this->faker->unique()->bothify('WH###'),
            'name' => $this->faker->company() . ' Warehouse',
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'priority' => $this->faker->numberBetween(1, 10),
            'is_active' => true,
            'no_explosion' => false,
            'no_lot' => false,
            'no_location' => false,
            'warehouse_type' => 'general',
        ];
    }
}
