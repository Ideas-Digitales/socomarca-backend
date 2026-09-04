<?php

namespace App\Http\Resources\Products;

use App\Services\VatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Get the most recent active price
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

        // With "vat=true" the prices are delivered with VAT included.
        $vat = app(VatService::class);
        $vatIncluded = $request->boolean('vat');
        $vatRate = $vatIncluded ? $vat->rate() : 0.0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'supercategory' => $this->supercategory,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'brand' => $this->brand,
            'vat' => $vatRate,
            'prices' => $this->prices->map(function ($price) use ($vat, $vatIncluded, $vatRate) {
                return [
                    'unit' => $price->unit,
                    'price' => $vatIncluded
                        ? $vat->applyTo((float) $price->price, $vatRate)
                        : $price->price,
                ];
            }),
            'sku' => $this->sku,
            'status' => $this->status,
            'image' => $this->image !== null ? Storage::url($this->image) : "",
            'is_favorite' => $isFavorite,
        ];
    }
}
