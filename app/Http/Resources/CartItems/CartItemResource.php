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
        $priceObj = $this->activePrices->firstWhere('unit', $this->unit);

        $price = $priceObj->price ?? 0;
        $unit = $priceObj->unit ?? $this->unit;
        $stock = $priceObj->stock ?? null;
        $totalPrice = $price * $this->quantity;

        return [
            "id" => $product->id,
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
            "price" => (int)$price,
            "stock" => (int)$stock,
            "image" => $product->image !== null ? Storage::url($product->image) : "",
            "sku" => $product->sku ?? null,
            "subtotal" => $totalPrice,
            "is_favorite" => false,

        ];
    }
}
