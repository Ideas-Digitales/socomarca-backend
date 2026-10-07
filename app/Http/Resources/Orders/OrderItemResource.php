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
            /**
             * Net unit price, as a decimal string.
             *
             * @example 3490.00
             */
            "price" => $this->price,
            /**
             * Random ERP price list the price was taken from; `null` on orders placed before it was stored.
             *
             * @var string|null
             */
            "price_list_id" => $this->price_list_id,
            /**
             * Net amount of the line (price × quantity), as a decimal string.
             *
             * @var string
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
             * Subtotal plus VAT, as a decimal string.
             *
             * @var string
             */
            "total" => $this->total,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
