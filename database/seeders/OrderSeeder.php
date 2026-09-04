<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = \App\Models\User::pluck('id')->toArray();
        $products = \App\Models\Product::pluck('id')->toArray();

        // Months of 2025 to populate
        $months = [
            '2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06'
        ];

        foreach ($months as $month) {
            // Create 10 orders per month (adjust the quantity if you wish)
            for ($i = 0; $i < 100; $i++) {
                $userId = fake()->randomElement($users);

                // Random date within the month
                $date = fake()->dateTimeBetween("$month-01", "$month-28");

                $subtotal = 0;
                $amount = 0;

                $user = User::find($userId);
                $address = $user->addresses()->where('type', 'billing')->first();
                $order_meta = [
                    'user' => $user->toArray(),
                    'address' => $address ? $address->toArray() : null,
                ];


                $vatRate = app(\App\Services\VatService::class)->rate();

                $order = \App\Models\Order::create([
                    'user_id' => $userId,
                    'subtotal' => 0,
                    'vat' => $vatRate,
                    'total' => 0,
                    'amount' => 0,
                    'status' => fake()->randomElement([
                        'pending', 'processing', 'on_hold', 'completed', 'canceled', 'refunded', 'failed'
                    ]),
                    'order_meta' => $order_meta,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                $itemsCount = rand(1, 5);
                $productIds = fake()->randomElements($products, $itemsCount);

                foreach ($productIds as $productId) {
                    $priceObj = \App\Models\Price::where('product_id', $productId)
                        ->where('is_active', true)
                        ->inRandomOrder()
                        ->first();

                    if (!$priceObj) continue;

                    $quantity = rand(1, 10);
                    $itemTotal = $priceObj->price * $quantity;
                    $subtotal += $itemTotal;

                    \App\Models\OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'unit' => $priceObj->unit,
                        'quantity' => $quantity,
                        'price' => $priceObj->price,
                        'subtotal' => $itemTotal,
                        'vat' => $vatRate,
                        'total' => round($itemTotal * (1 + $vatRate / 100), 2),
                    ]);
                }

                $total = round($subtotal * (1 + $vatRate / 100));
                $amount = $total; // You can adjust if you have discount/tax logic

                $order->update([
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'amount' => $amount,
                ]);
            }
        }
    }
}
