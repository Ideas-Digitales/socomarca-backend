<?php

namespace App\Http\Resources\Products;

use App\Services\VatService;
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
        // With "vat=true" the price is delivered with VAT included. The rate is resolved
        // only once because reading it queries siteinfo.
        $vat = app(VatService::class);
        $vatIncluded = $request->boolean('vat');
        $vatRate = $vatIncluded ? $vat->rate() : 0.0;

        return $this->collection->map(function ($product) use ($vatIncluded, $vatRate, $vat) {
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
                'price' => $vatIncluded
                    ? $vat->applyTo((float) $product->joined_price, $vatRate)
                    : (float) $product->joined_price,
                // VAT rate contained in the previous price; 0 if ordered without VAT.
                'vat' => $vatRate,
                'stock' => (int) $product->joined_stock,
                // Price list this row belongs to. The stored value is the human readable
                // name coming from Random; a product repeats once per price list.
                'price_list_id' => $product->joined_price_list_id,
                'image' => $imageUrl ?? null,
                'sku' => $product->sku ?? null,
                'is_favorite' => $isFavorite,
            ];
        })->values();
    }
}
