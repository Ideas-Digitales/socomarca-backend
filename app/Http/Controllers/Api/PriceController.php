<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use App\Models\Price;

#[Group('Prices', 'Prices synced from Random ERP, one per product, price list and unit.', weight: 8)]
class PriceController extends Controller
{
    /**
     * List prices
     *
     * Returns every stored price, of all price lists and including inactive ones, without pagination.
     */
    public function index()
    {
        return Price::all();
    }

}
