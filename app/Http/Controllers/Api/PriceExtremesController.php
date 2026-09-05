<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Price;
use App\Services\VatService;
use Illuminate\Http\Request;

class PriceExtremesController extends Controller
{
    public function __construct(private VatService $vatService) {}

    /**
     * Get the products with the lowest and highest price.
     *
     * Accepts the same parameter "vat=true" from the product list, so that the
     * Price filter ends are in the same currency as the displayed prices.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $vatRate = $request->boolean('vat') ? $this->vatService->rate() : 0.0;

        // Find the lowest price record (active)
        $minPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'asc')->first();

        // Find the highest price record (active)
        $maxPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'desc')->first();


        return response()->json([
            'lowest_price_product' => $minPriceRecord
                ? (int) $this->vatService->applyTo((float) $minPriceRecord->price, $vatRate, 0)
                : null,
            'highest_price_product' => $maxPriceRecord
                ? (int) $this->vatService->applyTo((float) $maxPriceRecord->price, $vatRate, 0)
                : null,
            'vat' => $vatRate,
        ]);
    }
}
