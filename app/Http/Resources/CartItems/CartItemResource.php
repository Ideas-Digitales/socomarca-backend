<?php

namespace App\Http\Resources\CartItems;

use App\Http\Resources\Products\ProductResource;
use App\Models\Price;
use App\Models\Product;
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

        $product = Product::where('id', $this->product_id)->first();
        $priceObj = Price::where('product_id', $product->id)->where('is_active', 1)->where('unit', $this->unit)->first();
        $price = $priceObj->price ?? 0;
        $unit = $priceObj->unit;
        $totalPrice = $price * $this->quantity;
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
            "unit" => $unit ?? null,
            "price" => (int)$price,
            "stock" => (int)$stock,
            "image" => $this->image !== null ? Storage::url($this->image) : "",
            "sku" => $product->sku ?? null,
            "subtotal" => $totalPrice,
            "is_favorite" => false,

        ];
    }
}
