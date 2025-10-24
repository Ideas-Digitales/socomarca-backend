<?php

namespace App\Http\Resources\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Obtiene el precio activo más reciente
        $activePrice = $this->prices()
            ->where('is_active', true)
            ->orderByDesc('valid_from')
            ->first();

        $isFavorite = false;

        $userId = 1;

        $isFavorite = $this->favorites()
            ->whereHas('favoriteList', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->exists();

        $imageRelative = $this->image ?? null;
        $imageUrl = null;
        if ($imageRelative) {
            $awsUrl = rtrim(config('filesystems.disks.s3.url') ?? env('AWS_URL'), '/');
            $bucket = config('filesystems.disks.s3.bucket') ?? env('AWS_BUCKET');
            $imageRelative = ltrim($imageRelative, '/');
            $imageUrl = "{$awsUrl}/{$bucket}/{$imageRelative}";
        }

        return
        [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'brand' => $this->brand,
            'prices' => $this->prices->map(function($price) {
                return [
                    'unit' => $price->unit,
                    'price' => $price->price,
                ];
            }),
            'stock' => $this->getTotalAvailableStock(),
            'stock_by_warehouse' => $this->getStockByWarehouse(),
            'sku' => $this->sku,
            'status' => $this->status,
            'image' => $imageUrl,
            'is_favorite' => $isFavorite,
        ];
    }
}
