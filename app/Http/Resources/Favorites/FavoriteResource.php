<?php

namespace App\Http\Resources\Favorites;

use App\Models\FavoriteList;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FavoriteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;

        // Encontrar el precio correspondiente a la unidad del favorito
        $priceData = $product->prices->firstWhere('unit', $this->unit);

        return [
            'id' => $this->id,
            'unit' => $this->unit,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'subcategory' => $product->subcategory ? [
                    'id' => $product->subcategory->id,
                    'name' => $product->subcategory->name,
                ] : null,
                'brand' => $product->brand ? [
                    'id' => $product->brand->id,
                    'name' => $product->brand->name,
                ] : null,
                'unit' => $priceData?->unit,
                'price' => (int) $priceData?->price,
                'stock' => $priceData?->stock,
                'image' => Storage::url($product?->image ?? ""),
                'sku' => $product->sku,
            ]
        ];
    }
}
