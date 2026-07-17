<?php

namespace Tests\Scenarios;

use App\Enums\PaymentDocumentType;
use App\Models\Branch;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;

class WebpayReturnScenario
{
    public function __construct(
        public User $user,
        public Branch $branch,
        public Order $order,
        public Product $product,
        public Payment $payment,
    ) {}

    public static function make(array $branchAttributes = [], string $token = 'fake_token_ws'): WebpayReturnScenario
    {
        $user = User::factory()->create([
            'rut' => '12345678-9',
            'user_code' => '12345678-9',
        ]);
        $user->assignRole('customer');

        $branch = Branch::factory()->create(array_merge(['user_id' => $user->id], $branchAttributes));

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'amount' => 10000,
            'branch_id' => $branch->id,
            'notes' => '',
            'random_document_number' => null,
        ]);

        $product = Product::factory()->create(['sku' => 'TEST-SKU-123']);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        CartItem::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $paymentMethod = PaymentMethod::factory()->create(['code' => 'webpay']);

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'token' => $token,
            'status' => 'pending',
            'response_status' => 'INITIALIZED',
            'generate_random_doc_type' => PaymentDocumentType::INVOICE,
        ]);

        return new WebpayReturnScenario($user, $branch, $order, $product, $payment);
    }
}
