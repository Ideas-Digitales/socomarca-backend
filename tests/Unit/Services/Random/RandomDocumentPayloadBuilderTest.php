<?php

use App\Enums\PaymentDocumentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Random\RandomDocumentPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buildOrderWithItems(): array
{
    $user = User::factory()->create([
        "rut" => "11111111-1",
        "user_code" => "11111111-1",
        "branch_code" => "CM",
    ]);
    $order = Order::factory()->create([
        "user_id" => $user->id,
        "notes" => "Ring the bell",
    ]);

    $product = Product::factory()->create(["sku" => "SKU-123"]);
    OrderItem::factory()->create([
        "order_id" => $order->id,
        "product_id" => $product->id,
        "quantity" => 3,
    ]);

    return [$order, $product];
}

test(
    "builds the receipt payload with the receipt sale flow option",
    function () {
        [$order, $product] = buildOrderWithItems();

        $payload = (new RandomDocumentPayloadBuilder())->build(
            $order,
            PaymentDocumentType::RECEIPT,
            "Pago por Webpay",
        );

        expect($payload["datos"]["codigoEntidad"])->toBe("11111111-1");
        expect($payload["datos"]["sucursalEntidad"])->toBe("CM");
        expect($payload["datos"])->not->toHaveKey("sucursalEntidadDespacho");
        expect($payload["datos"]["flujoVenta"])->toBe("NVVBLV");
        expect($payload["datos"]["tido"])->toBe("NVV");
        expect($payload["datos"]["lineas"])->toBe([
            ["cantidad" => 3, "codigoProducto" => $product->sku],
        ]);
        expect($payload["datos"]["texto1"])->toBe(
            "Pago por Webpay. Orden de compra: #{$order->id}",
        );
        expect($payload["datos"]["texto2"])->toBe("11111111-1 - Boleta");
        expect($payload["datos"]["observacion"])->toBe("Ring the bell");
    },
);

test(
    "builds the invoice payload with the invoice sale flow option",
    function () {
        [$order] = buildOrderWithItems();

        $payload = (new RandomDocumentPayloadBuilder())->build(
            $order,
            PaymentDocumentType::INVOICE,
            "Pago por Webpay",
        );

        expect($payload["datos"]["flujoVenta"])->toBe("NVVFCV");
        expect($payload["datos"]["texto2"])->toBe("11111111-1 - Factura");
    },
);

test(
    "issues the document with the buyer entity code and the branch of the customer",
    function () {
        [$order] = buildOrderWithItems();
        $branch = User::factory()->create([
            "rut" => "11111111-1",
            "user_code" => "11111111-1",
            "branch_code" => "LO",
        ]);
        $order->update(["customer_id" => $branch->id]);

        $payload = (new RandomDocumentPayloadBuilder())->build(
            $order->fresh(),
            PaymentDocumentType::RECEIPT,
            "Pago por Webpay",
        );

        expect($payload["datos"]["codigoEntidad"])->toBe("11111111-1");
        expect($payload["datos"]["sucursalEntidad"])->toBe("LO");
        expect($payload["datos"]["texto2"])->toBe("11111111-1 - Boleta");
    },
);

test(
    "credit-line payloads derive the sale flow option from the chosen document type, same as webpay",
    function () {
        [$order] = buildOrderWithItems();

        $receiptPayload = (new RandomDocumentPayloadBuilder())->build(
            $order,
            PaymentDocumentType::RECEIPT,
            "Pago a crédito",
        );
        $invoicePayload = (new RandomDocumentPayloadBuilder())->build(
            $order,
            PaymentDocumentType::INVOICE,
            "Pago a crédito",
        );

        expect($receiptPayload["datos"]["flujoVenta"])->toBe("NVVBLV");
        expect($invoicePayload["datos"]["flujoVenta"])->toBe("NVVFCV");
    },
);
