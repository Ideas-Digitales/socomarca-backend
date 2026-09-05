<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 10, 1000);
        $quantity = fake()->numberBetween(1, 10);
        $subtotal = round($price * $quantity, 2);
        $vatRate = (float) config('vat.rate');

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'unit' => fake()->randomElement(['kg', 'g', 'l', 'ml', 'unidad']),
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
            'vat' => $vatRate,
            'total' => round($subtotal * (1 + $vatRate / 100), 2),
        ];
    }
} 