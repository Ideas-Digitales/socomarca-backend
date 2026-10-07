<?php

namespace Tests\Scenarios;

use App\Enums\BranchType;
use App\Enums\PaymentDocumentType;
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
        public User $customer,
        public Order $order,
        public Product $product,
        public Payment $payment,
    ) {}

    /**
     * @param array|null $secondaryBranchAttributes When given, the order is placed for a secondary
     *      branch of the user's entity with these attributes; otherwise, for the user itself.
     */
    public static function make(?array $secondaryBranchAttributes = null, string $token = 'fake_token_ws'): WebpayReturnScenario
    {
        $user = User::factory()->create([
            'rut' => '12345678-9',
            'user_code' => '12345678-9',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
        ]);
        $user->assignRole('customer');

        $customer = $user;

        if ($secondaryBranchAttributes !== null) {
            $customer = User::factory()->create(array_merge([
                'rut' => '12345678-9',
                'user_code' => '12345678-9',
                'branch_code' => 'LO',
                'branch_type' => BranchType::SECONDARY,
            ], $secondaryBranchAttributes));
            $customer->assignRole('customer');
        }

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
            'amount' => 10000,
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

        return new WebpayReturnScenario($user, $customer, $order, $product, $payment);
    }
}
