<?php

namespace App\Http\Resources\Orders;

use App\Http\Resources\PaymentResource;
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
        return [
            "id" => $this->id,
            /** User who placed the order. */
            "user" => $this->user,
            /**
             * Net amount of the items, without VAT.
             *
             * @var float
             */
            "subtotal" => $this->subtotal,
            /**
             * VAT rate applied, in percentage.
             *
             * @var float
             */
            "vat" => $this->vat,
            /** @var float */
            "vat_amount" => $this->vat_amount,
            /**
             * Subtotal plus VAT, without shipping cost.
             *
             * @var float
             */
            "total" => $this->total,
            /** @var float */
            "shipping_cost" => $this->shipping_cost,
            /**
             * Amount charged: total plus shipping cost.
             *
             * @var float
             */
            "amount" => $this->amount,
            /** @var 'pending'|'processing'|'on_hold'|'completed'|'canceled'|'refunded'|'failed' */
            "status" => $this->status,
            "order_items" => OrderItemResource::collection($this->orderDetails),
            /**
             * Snapshot, at checkout time, of the user who placed the order and of the delivery address.
             *
             * @var array{user: array<string, mixed>, address: array<string, mixed>|null}|null
             */
            "order_meta" => $this->order_meta,
            "payments" => PaymentResource::collection(
                $this->whenLoaded('payments')
            ),
            /** User (Random ERP branch) the order is placed for. */
            "customer" => $this->whenLoaded('customer', fn () => [
                "id" => $this->customer->id,
                "name" => $this->customer->name,
                /** Random ERP entity code (KOEN). */
                "user_code" => $this->customer->user_code,
                /** Random ERP branch code (SUEN). */
                "branch_code" => $this->customer->branch_code,
                /**
                 * Random ERP branch type: `P` primary, `S` secondary.
                 *
                 * @var 'P'|'S'|null
                 */
                "branch_type" => $this->customer->branch_type,
            ]),
            /** Number of the sales note (NVV) created in Random ERP; `null` until it is created. */
            "random_document_number" => $this->random_document_number,
            'notes' => $this->notes,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
