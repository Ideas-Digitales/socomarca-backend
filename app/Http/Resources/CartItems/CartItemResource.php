<?php

namespace App\Http\Resources\CartItems;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $priceObj = $this->resolvedPrice;

        $price = $priceObj->price ?? 0;
        $unit = $priceObj->unit ?? $this->unit;
        $stock = $priceObj->stock ?? null;
        $totalPrice = $price * $this->quantity;

        return [
            /** Product ID. */
            "id" => $product->id,
            /** Product name. */
            "name" => $product->name,
            "category" => $product->category ? [
                "id" => $product->category->id,
                "name" => $product->category->name,
            ] : null,
            "subcategory" => $product->subcategory ? [
                "id" => $product->subcategory->id,
                "name" => $product->subcategory->name,
            ] : null,
            "brand" => $product->brand ? [
                "id" => $product->brand->id,
                "name" => $product->brand->name,
            ] : null,
            "quantity" => (int)$this->quantity,
            "unit" => $unit,
            /** Unit price from the user's price lists; 0 when the user has no active price for the unit. */
            "price" => (int)$price,
            /** Stock of the price's unit; 0 when there is no price. */
            "stock" => (int)$stock,
            /** Product image URL; empty string when the product has no image. */
            "image" => $product->image !== null ? Storage::url($product->image) : "",
            "sku" => $product->sku ?? null,
            /**
             * Unit price × quantity.
             *
             * @var float
             */
            "subtotal" => $totalPrice,
            /** Always `false` in the cart. */
            "is_favorite" => false,

        ];
    }
}
