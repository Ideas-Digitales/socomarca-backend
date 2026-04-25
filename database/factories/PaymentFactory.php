<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => \App\Models\Order::factory(),
            'payment_method_id' => \App\Models\PaymentMethod::factory(),
            'auth_code' => $this->faker->bothify('???###'),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'response_status' => $this
                ->faker
                ->randomElement(['success', 'failed', 'pending']),
            'response_message' => [
                'message' => $this->faker->sentence()
            ],
            'token' => $this->faker->unique()->uuid(),
            'paid_at' => $this->faker->optional()->dateTime(),
            'status' => $this->faker->randomElement([
                'pending',
                'completed',
                'failed'
            ]),
        ];
    }
}
