<?php

namespace App\Http\Resources\Orders;

use App\Http\Resources\PaymentResource;
use App\Services\Orders\CancelAwaitingInvoiceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $countdown = app(CancelAwaitingInvoiceOrder::class)->countdown(
            $this->resource,
            $request->user(),
        );

        return [
            'id' => $this->id,
            /** User who placed the order. */
            'user' => $this->user,
            /**
             * Net amount of the items, without VAT.
             *
             * @var float
             */
            'subtotal' => $this->subtotal,
            /**
             * VAT rate applied, in percentage.
             *
             * @var float
             */
            'vat' => $this->vat,
            /** @var float */
            'vat_amount' => $this->vat_amount,
            /**
             * Subtotal plus VAT, without shipping cost.
             *
             * @var float
             */
            'total' => $this->total,
            /** @var float */
            'shipping_cost' => $this->shipping_cost,
            /**
             * Amount charged: total plus shipping cost.
             *
             * @var float
             */
            'amount' => $this->amount,
            /** @var 'pending'|'processing'|'on_hold'|'completed'|'canceled'|'refunded'|'failed' */
            'status' => $this->status,
            'order_items' => OrderItemResource::collection($this->orderDetails),
            /**
             * Snapshot, at checkout time, of the user who placed the order, the delivery address and the contact.
             *
             * @var array{user: array<string, mixed>, address: array<string, mixed>|null, contact?: array<string, mixed>}|null
             */
            'order_meta' => $this->order_meta,
            /** Contact chosen at checkout. `null` on orders placed before contacts existed. */
            'contact_id' => $this->contact_id,
            /**
             * RUT of that contact, frozen at checkout and sent to Random ERP as `rutContacto`.
             *
             * @var string|null
             */
            'contact_rut' => $this->contact_rut,
            'payments' => PaymentResource::collection(
                $this->whenLoaded('payments')
            ),
            /** User (Random ERP branch) the order ships to; `user_id` placed, paid and is billed for it. */
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                /** Random ERP entity code (KOEN). */
                'user_code' => $this->customer->user_code,
                /** Random ERP branch code (SUEN). */
                'branch_code' => $this->customer->branch_code,
                /**
                 * Random ERP branch type: `P` primary, `S` secondary.
                 *
                 * @var 'P'|'S'|null
                 */
                'branch_type' => $this->customer->branch_type,
            ]),
            /** Number of the sales note (NVV) created in Random ERP; `null` until it is created. */
            'random_document_number' => $this->random_document_number,
            'notes' => $this->notes,
            /**
             * Moment until which the buyer can cancel this order, ISO 8601.
             * Null when the authenticated user cannot cancel it.
             *
             * @var string|null
             *
             * @format date-time
             */
            'cancelable_until' => $countdown['cancelable_until'] ?? null,
            /**
             * Seconds left in that window when this response was built.
             * Zero once the window has elapsed. Null when the authenticated user cannot cancel it.
             *
             * @var int|null
             */
            'cancel_seconds_remaining' => $countdown['cancel_seconds_remaining'] ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
