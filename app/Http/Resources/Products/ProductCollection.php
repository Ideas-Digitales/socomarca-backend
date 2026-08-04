<?php

namespace App\Http\Resources\Products;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class ProductCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request)
    {
        return $this->collection->map(function ($product) {
            $isFavorite = false;
            if (Auth::check()) {
                $isFavorite = $product->favorites()->whereHas('favoriteList', function ($q) {
                    $q->where('user_id', Auth::id());
                })->exists();
            }

            $imageRelative = $product->image ?? null;
            $imageUrl = null;
            if ($imageRelative) {
                $awsUrl = rtrim(config('filesystems.disks.s3.url') ?? env('AWS_URL'), '/');
                $imageRelative = ltrim($imageRelative, '/');
                $imageUrl = "{$awsUrl}/{$imageRelative}";
            }

            return [
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
                'unit' => $product->joined_unit,
                'price' => (float) $product->joined_price,
                'stock' => (int) $product->joined_stock,
                // Lista de precios a la que corresponde esta fila. El valor es el nombre
                // legible que entrega Random; un producto se repite una vez por lista.
                'price_list_id' => $product->joined_price_list_id,
                'image' => $imageUrl ?? null,
                'sku' => $product->sku ?? null,
                'is_favorite' => $isFavorite,
            ];
        })->values();
    }
}
