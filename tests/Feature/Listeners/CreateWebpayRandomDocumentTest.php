<?php

namespace Tests\Feature\Listeners;

use App\Enums\PaymentDocumentType;
use App\Events\WebpayPaymentAuthorized;
use App\Listeners\CreateWebpayRandomDocument;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RandomDocument;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Random\RandomDocumentPayloadBuilder;
use App\Services\Random\RandomDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(RefreshDatabase::class);

function buildAuthorizedWebpayScenario(): array
{
    $user = User::factory()->create([
        "rut" => "12345678-9",
        "user_code" => "12345678-9",
    ]);
    $branch = Branch::factory()->create(["user_id" => $user->id]);
    $order = Order::factory()->create([
        "user_id" => $user->id,
        "status" => "completed",
        "branch_id" => $branch->id,
        "notes" => "",
        "random_document_number" => null,
    ]);
    $product = Product::factory()->create(["sku" => "TEST-SKU-123"]);
    OrderItem::factory()->create([
        "order_id" => $order->id,
        "product_id" => $product->id,
        "quantity" => 2,
    ]);

    $paymentMethod = PaymentMethod::where("code", "transbank")->firstOrFail();
    $payment = Payment::factory()->create([
        "order_id" => $order->id,
        "payment_method_id" => $paymentMethod->id,
        "response_status" => "AUTHORIZED",
        "generate_random_doc_type" => PaymentDocumentType::RECEIPT,
    ]);

    return [$order, $payment];
}

function makeListener(): CreateWebpayRandomDocument
{
    /** @var TestCase $this */
    return new CreateWebpayRandomDocument(
        new RandomDocumentPayloadBuilder(),
        app(RandomDocumentService::class),
        new PaymentService(),
    );
}

test("listener implements ShouldQueue", function () {
    expect(makeListener())->toBeInstanceOf(ShouldQueue::class);
});

test(
    "creates the random document and attaches it to the order on success",
    function () {
        /** @var TestCase $this */
        [$order, $payment] = buildAuthorizedWebpayScenario();

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/web32/documento" => Http::response(
                [
                    "numero" => "0000000099",
                    "idmaeedo" => 321,
                ],
                200,
            ),
        ]);

        makeListener()->handle(new WebpayPaymentAuthorized($order, $payment));

        expect($order->fresh()->random_document_number)->toBe("0000000099");
        expect($order->randomDocuments()->count())->toBe(1);
        expect($order->randomDocuments()->first()->idmaeedo)->toBe(321);
    },
);

test(
    "logs and leaves the order untouched on a Random ERP business error",
    function () {
        /** @var TestCase $this */
        [$order, $payment] = buildAuthorizedWebpayScenario();

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/web32/documento" => Http::response(
                [
                    "message" => "invalid data",
                    "errorId" => "aBcD1234",
                ],
                200,
            ),
        ]);

        Log::shouldReceive("error")->once();

        makeListener()->handle(new WebpayPaymentAuthorized($order, $payment));

        expect($order->fresh()->random_document_number)->toBeNull();
        expect($order->fresh()->status)->toBe("completed");
    },
);

test(
    "logs and swallows a thrown exception without failing the order",
    function () {
        /** @var TestCase $this */
        [$order, $payment] = buildAuthorizedWebpayScenario();

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/web32/documento" => Http::response(
                ["message" => "boom"],
                500,
            ),
        ]);

        Log::shouldReceive("error")->atLeast()->once();

        makeListener()->handle(new WebpayPaymentAuthorized($order, $payment));

        expect($order->fresh()->random_document_number)->toBeNull();
        expect($order->fresh()->status)->toBe("completed");
    },
);

test(
    "skips creating a document when the order already has one attached",
    function () {
        /** @var TestCase $this */
        [$order, $payment] = buildAuthorizedWebpayScenario();

        $randomDocument = RandomDocument::create([
            "idmaeedo" => 555,
            "type" => "NVV",
            "document" => ["numero" => "0000000001"],
        ]);
        $order->randomDocuments()->attach($randomDocument->idmaeedo);

        Http::fake();

        makeListener()->handle(new WebpayPaymentAuthorized($order, $payment));

        Http::assertNothingSent();
    },
);
