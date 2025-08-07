<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Price;
use Illuminate\Http\Request;

class PriceExtremesController extends Controller
{
    /**
     * Get the products with the lowest and highest price.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index   ()
    {
        // Encuentra el registro de precio más bajo (activo)
        $minPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'asc')->first();

        // Encuentra el registro de precio más alto (activo)
        $maxPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'desc')->first();


        return response()->json([
            'lowest_price_product' => $minPriceRecord ? (int) $minPriceRecord->price : null,
            'highest_price_product' => $maxPriceRecord ? (int) $maxPriceRecord->price : null,
        ]);
    }
}
