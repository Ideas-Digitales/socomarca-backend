<?php

namespace App\Services\Random;

use App\Enums\PaymentDocumentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\SecondaryBranchesScope;

class RandomDocumentPayloadBuilder
{
    /**
     * Build the Random ERP "documento" (NVV) payload for an order.
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
        $branch = $order
            ->branch()
            ->withoutGlobalScope(SecondaryBranchesScope::class)
            ->first();

        return [
            "datos" => [
                "empresa" => config("random.business_code"),
                "codigoEntidad" => $order->user->user_code,
                "sucursalEntidad" => $branch->code,
                "sucursalEntidadDespacho" => $branch->code,
                "flujoVenta" => PaymentDocumentType::getSaleFlowOption(
                    $generateRandomDocType,
                ),
                "tido" => "NVV",
                "moneda" => "CLP",
                "modalidad" => config("random.modality"),
                "funcionario" => config("random.functionary"),
                "lineas" => $lines,
                "texto1" => "{$paymentLabel}. Orden de compra: #{$order->id}",
                "texto2" => "{$order?->user?->rut} - {$randomDocType}",
                "texto3" => "Origen: Compra rápida",
                "observacion" => $order->notes,
            ],
        ];
    }
}
