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
            /** Favorite ID, used to remove it. */
            'id' => $this->id,
            /** Sale unit saved in the favorite. */
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
                /**
                 * Unit of the product price shown; `null` when the product has no price for the favorite's unit.
                 *
                 * @var string|null
                 */
                'unit' => $priceData?->unit,
                /** Price of the first product price for the favorite's unit, from any price list (not only the user's); 0 when there is none. */
                'price' => (int) $priceData?->price,
                /** @var int|null */
                'stock' => $priceData?->stock,
                /** Product image URL; the bare storage base URL when the product has no image. */
                'image' => Storage::url($product?->image ?? ""),
                /** @var string */
                'sku' => $product->sku,
            ]
        ];
    }
}
