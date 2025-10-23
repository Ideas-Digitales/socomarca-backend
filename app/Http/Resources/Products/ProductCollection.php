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
                $bucket = config('filesystems.disks.s3.bucket') ?? env('AWS_BUCKET');
                $imageRelative = ltrim($imageRelative, '/');
                $imageUrl = "{$awsUrl}/{$bucket}/{$imageRelative}";
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
                'unit' => $product->joined_unit
                    ?? optional($product->prices()->where('is_active', true)->orderByDesc('valid_from')->first())->unit,
                'price' => isset($product->joined_price)
                    ? (float) $product->joined_price
                    : (float) optional($product->prices()->where('is_active', true)->orderByDesc('valid_from')->first())->price,
                'stock' => isset($product->joined_stock)
                    ? (int) $product->joined_stock
                    : (int) optional($product->prices()->where('is_active', true)->orderByDesc('valid_from')->first())->stock,
                'image' => $imageUrl ?? null,
                'sku' => $product->sku ?? null,
                'is_favorite' => $isFavorite,
            ];
        })->values();
    }
}