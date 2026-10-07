<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Dedoc\Scramble\Attributes\Group;

#[Group('Brands', 'List the product brands synced from Random ERP.', weight: 6)]
class BrandController extends Controller
{
    /**
     * List brands
     *
     * Lists, sorted by name, the brands with at least one active product that has a visible price: active,
     * in stock, above zero (unless zero prices are enabled in the configuration) and, for customers, on one
     * of their price lists. Not paginated.
     *
     * @see \App\Models\Price::visibleTo()
     * @return \Illuminate\Database\Eloquent\Collection<int, Brand>
     */
    public function index()
    {
        return Brand::whereHas('products', function ($query) {
            $query->where('status', true)
                ->whereHas('prices', fn ($priceQuery) => $priceQuery->visibleTo());
        })
            ->orderBy('name')
            ->get();
    }
}
