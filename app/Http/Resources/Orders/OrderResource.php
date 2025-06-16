<?php

namespace App\Http\Resources\Orders;

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
            "amount" => $this->amount,
            "status" => $this->status,
            "order_items" => OrderItemResource::collection($this->orderDetails),
            "order_meta" => $this->order_meta,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
