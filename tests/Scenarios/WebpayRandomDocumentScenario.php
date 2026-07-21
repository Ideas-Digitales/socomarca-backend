<?php

namespace Tests\Scenarios;

use App\Enums\PaymentDocumentType;
use App\Listeners\CreateWebpayRandomDocument;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Random\RandomDocumentPayloadBuilder;
use App\Services\Random\RandomDocumentService;

class WebpayRandomDocumentScenario
{
    public function __construct(
        public Order $order,
        public Payment $payment,
    ) {}

    public static function make(): WebpayRandomDocumentScenario
    {
        $user = User::factory()->create([
            'rut' => '12345678-9',
            'user_code' => '12345678-9',
        ]);
        $branch = Branch::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
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

        $paymentMethod = PaymentMethod::where('code', 'transbank')->firstOrFail();
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'response_status' => 'AUTHORIZED',
            'generate_random_doc_type' => PaymentDocumentType::RECEIPT,
        ]);

        return new WebpayRandomDocumentScenario($order, $payment);
    }

    public static function makeListener(): CreateWebpayRandomDocument
    {
        return new CreateWebpayRandomDocument(
            new RandomDocumentPayloadBuilder(),
            app(RandomDocumentService::class),
            new PaymentService(),
        );
    }
}
