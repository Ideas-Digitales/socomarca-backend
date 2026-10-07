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
                /** @var int */
                'id' => $product->id,
                /** @var string */
                'name' => $product->name,
                'category' => $product->category ? [
                    /** @var int */
                    'id' => $product->category->id,
                    /** @var string */
                    'name' => $product->category->name,
                ] : null,
                'subcategory' => $product->subcategory ? [
                    /** @var int */
                    'id' => $product->subcategory->id,
                    /** @var string */
                    'name' => $product->subcategory->name,
                ] : null,
                'brand' => $product->brand ? [
                    /** @var int */
                    'id' => $product->brand->id,
                    /** @var string */
                    'name' => $product->brand->name,
                ] : null,
                /**
                 * Sale unit of this price (Random ERP unit code).
                 *
                 * @var string
                 * @example UN
                 */
                'unit' => $product->joined_unit,
                /**
                 * Unit price of this row: net, or with VAT included when `vat=true`.
                 *
                 * @var float
                 */
                'price' => $vatIncluded
                    ? $vat->applyTo((float) $product->joined_price, $vatRate)
                    : (float) $product->joined_price,
                /**
                 * VAT rate (percentage) included in `price`; `0` when prices are net.
                 *
                 * @var float
                 */
                'vat' => $vatRate,
                /** Stock available for this price. */
                'stock' => (int) $product->joined_stock,
                /**
                 * Random ERP price list of this row. A product appears once per visible price list and unit.
                 *
                 * @var string
                 */
                'price_list_id' => $product->joined_price_list_id,
                /** Absolute URL of the product image, or `null` when it has none. */
                'image' => $imageUrl ?? null,
                /** @var string */
                'sku' => $product->sku ?? null,
                /**
                 * Whether the product is in one of the authenticated user's favorite lists.
                 *
                 * @var bool
                 */
                'is_favorite' => $isFavorite,
            ];
        })->values();
    }
}
