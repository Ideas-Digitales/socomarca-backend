<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Region;
use App\Models\Municipality;
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 1000, 100000);

        //Factory 10 Region and Municipality
        $regions = Region::factory()->count(10)->create();
        foreach ($regions as $region) {
            Municipality::factory()->count(10)->create([
                'region_id' => $region->id
            ]);
        }

        $meta = [
            'name' => fake()->name(),
            'rut' => fake()->numerify('########-#'),
            'email' => fake()->email(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'region_id' => Region::inRandomOrder()->first()->id,
            'municipality_id' => Municipality::inRandomOrder()->first()->id,
            'billing_address' => fake()->address(),
            'billing_address_details' => fake()->address(),
        ];
        
        $shippingCost = $subtotal >= 70000 ? 0 : (int) round($subtotal * 0.1);

        return [
            'user_id' => User::factory(),
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingCost,
            'amount' => $subtotal + $shippingCost,
            'status' => fake()->randomElement(['pending', 'processing', 'on_hold', 'completed', 'canceled', 'refunded', 'failed']),
            'order_meta' => json_encode($meta),
        ];
    }

    /**
     * Indicate that the order is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the order is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the order is canceled.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
        ]);
    }
}