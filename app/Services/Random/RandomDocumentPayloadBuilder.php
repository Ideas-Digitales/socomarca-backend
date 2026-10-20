<?php

namespace App\Services\Random;

use App\Enums\PaymentDocumentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\VatService;

class RandomDocumentPayloadBuilder
{
    public function __construct(private VatService $vatService) {}

    /**
     * Build the Random ERP "documento" (NVV) payload for an order.
     *
     * The entity code (KOEN) is the one of the user who placed the order and the branch
     * (SUEN) is the one of the customer the order is placed for, which can be one of the
     * user's secondary branches.
     *
     * @param  string  $generateRandomDocType  Payment document type chosen by the customer
     *                                         (PaymentDocumentType::INVOICE|RECEIPT). Drives both the human-readable label
     *                                         (texto2) and the Random ERP flujoVenta code, for credit and Webpay sales alike.
     * @param  string  $paymentLabel  Origin label used in texto1, e.g. "Pago a crédito" or "Pago por Webpay".
     */
    public function build(
        Order $order,
        string $generateRandomDocType,
        string $paymentLabel,
    ): array {
        $orderItems = OrderItem::where('order_id', $order->id)
            ->with('product')
            ->get();
        $lines = $orderItems
            ->map(
                fn (OrderItem $item) => [
                    'cantidad' => $item->quantity,
                    'codigoProducto' => $item->product->sku,
                    'idUnidad' => 1,
                    'precioNetoUnitario' => (float) $item->price,
                    'precioBrutoUnitario' => $this->vatService->applyTo(
                        (float) $item->price,
                        $item->vat,
                    ),
                    // Same amounts that were charged when the order was placed.
                    'valorNetoLinea' => (float) $item->subtotal,
                    'valorBrutoLinea' => (float) $item->total,
                    'despacho' => true,
                ],
            )
            ->toArray();

        $randomDocType = PaymentDocumentType::getLabel($generateRandomDocType);
        $buyer = $order->user;

        return [
            'datos' => [
                'empresa' => config('random.business_code'),
                'codigoEntidad' => $buyer->user_code,
                // RUT of the contact person chosen at checkout. Omitted when the order has none.
                ...(filled($order->contact_rut) ? ['rutContacto' => $order->contact_rut] : []),
                'sucursalEntidad' => $order->customer->branch_code,
                'flujoVenta' => PaymentDocumentType::getSaleFlowOption(
                    $generateRandomDocType,
                ),
                'tido' => 'NVV',
                'moneda' => 'CLP',
                'modalidad' => config('random.modality'),
                'funcionario' => config('random.functionary'),
                'lineas' => $lines,
                'texto1' => "{$paymentLabel}. Orden de compra: #{$order->id}",
                'texto2' => "{$buyer->rut} - {$randomDocType}",
                'texto3' => 'Origen: Compra rápida',
                'observacion' => $order->notes,
            ],
        ];
    }
}
