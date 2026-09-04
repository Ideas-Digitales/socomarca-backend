<?php

namespace App\Http\Resources\Orders;

use App\Http\Resources\Products\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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
            "product" => new ProductResource($this->product),
            "unit" => $this->unit,
            "quantity" => $this->quantity,
            "price" => $this->price,
            "subtotal" => $this->subtotal,
            "vat" => $this->vat,
            "vat_amount" => $this->vat_amount,
            "total" => $this->total,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
