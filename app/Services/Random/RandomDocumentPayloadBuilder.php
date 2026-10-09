<?php

namespace App\Services\Random;

use App\Enums\PaymentDocumentType;
use App\Models\Order;
use App\Models\OrderItem;

class RandomDocumentPayloadBuilder
{
    /**
     * Build the Random ERP "documento" (NVV) payload for an order.
     *
     * The document is issued for the entity branch of the user who placed the order, so
     * Random prices it and charges its credit with that branch; the branch the order ships
     * to (its customer, which can be one of the user's secondary branches) is the
     * shipping branch.
     *
     * @param Order $order
     * @param string $generateRandomDocType Payment document type chosen by the customer
     *      (PaymentDocumentType::INVOICE|RECEIPT). Drives both the human-readable label
     *      (texto2) and the Random ERP flujoVenta code, for credit and Webpay sales alike.
     * @param string $paymentLabel Origin label used in texto1, e.g. "Pago a crédito" or "Pago por Webpay".
     */
    public function build(
        Order $order,
        string $generateRandomDocType,
        string $paymentLabel,
    ): array {
        $orderItems = OrderItem::where("order_id", $order->id)
            ->with("product")
            ->get();
        $lines = $orderItems
            ->map(function (OrderItem $item) {
                return [
                    "cantidad" => $item->quantity,
                    "codigoProducto" => $item->product->sku,
                ];
            })
            ->toArray();

        $randomDocType = PaymentDocumentType::getLabel($generateRandomDocType);
        $buyer = $order->user;

        return [
            "datos" => [
                "empresa" => config("random.business_code"),
                "codigoEntidad" => $buyer->user_code,
                "sucursalEntidad" => $buyer->branch_code,
                "sucursalEntidadDespacho" => $order->customer->branch_code,
                "flujoVenta" => PaymentDocumentType::getSaleFlowOption(
                    $generateRandomDocType,
                ),
                "tido" => "NVV",
                "moneda" => "CLP",
                "modalidad" => config("random.modality"),
                "funcionario" => config("random.functionary"),
                "lineas" => $lines,
                "texto1" => "{$paymentLabel}. Orden de compra: #{$order->id}",
                "texto2" => "{$buyer->rut} - {$randomDocType}",
                "texto3" => "Origen: Compra rápida",
                "observacion" => $order->notes,
            ],
        ];
    }
}
