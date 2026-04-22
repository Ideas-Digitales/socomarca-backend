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
            "user" => $this->user,
            "subtotal" => $this->subtotal,
            "shipping_cost" => $this->shipping_cost,
            "amount" => $this->amount,
            "status" => $this->status,
            "order_items" => OrderItemResource::collection($this->orderDetails),
            "order_meta" => $this->order_meta,
            "payments" => PaymentResource::collection(
                $this->whenLoaded('payments')
            ),
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
