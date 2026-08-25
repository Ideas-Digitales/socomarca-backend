<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;

class BrandController extends Controller
{
    /**
     * List the brands that hold at least one product with a price visible to the
     * current user, sorted alphabetically.
     *
     * "Visible" means in stock and, unless config('random.show_product_zero_price'),
     * priced above zero, on a price list the user may read. The rule lives in
     * Price::visibleTo() so brands, the category tree and the product listing can
     * never disagree about what is on offer.
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
