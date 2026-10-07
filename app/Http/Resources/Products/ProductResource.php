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
            /** @var \App\Models\Category|null */
            'supercategory' => $this->supercategory,
            /** @var \App\Models\Category|null */
            'category' => $this->category,
            /** @var \App\Models\Category|null */
            'subcategory' => $this->subcategory,
            /** @var \App\Models\Brand|null */
            'brand' => $this->brand,
            /**
             * VAT rate (percentage) included in `prices`; `0` when prices are net.
             *
             * @var float
             */
            'vat' => $vatRate,
            /** Every price of the product, of all price lists and units. */
            'prices' => $this->prices->map(function ($price) use ($vat, $vatIncluded, $vatRate) {
                return [
                    /**
                     * Sale unit (Random ERP unit code).
                     *
                     * @var string
                     * @example UN
                     */
                    'unit' => $price->unit,
                    /**
                     * Net price as a decimal string, or a number with VAT included when `vat=true`.
                     *
                     * @var string|float
                     */
                    'price' => $vatIncluded
                        ? $vat->applyTo((float) $price->price, $vatRate)
                        : $price->price,
                ];
            }),
            'sku' => $this->sku,
            /**
             * Whether the product is active.
             *
             * @var bool
             */
            'status' => $this->status,
            /** Image URL, or an empty string when the product has none. */
            'image' => $this->image !== null ? Storage::url($this->image) : "",
            /** @var bool */
            'is_favorite' => $isFavorite,
        ];
    }
}
