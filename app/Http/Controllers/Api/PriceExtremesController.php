<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Price;
use App\Services\VatService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

#[Group('Products', weight: 7)]
class PriceExtremesController extends Controller
{
    public function __construct(private VatService $vatService) {}

    /**
     * Get the price range
     *
     * Returns the lowest and highest active price, rounded to whole pesos, to set the bounds of the
     * price filter. Considers the active prices of every price list, regardless of the user's price
     * lists and of stock.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('vat', 'Return the prices with VAT included, matching the prices shown by List products.', type: 'bool', default: false)]
    public function index(Request $request)
    {
        $vatRate = $request->boolean('vat') ? $this->vatService->rate() : 0.0;

        // Find the lowest price record (active)
        $minPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'asc')->first();

        // Find the highest price record (active)
        $maxPriceRecord = Price::select('price')->where('is_active', true)->orderBy('price', 'desc')->first();


        return response()->json([
            /** Lowest active price, or `null` when there are no active prices. */
            'lowest_price_product' => $minPriceRecord
                ? (int) $this->vatService->applyTo((float) $minPriceRecord->price, $vatRate, 0)
                : null,
            /** Highest active price, or `null` when there are no active prices. */
            'highest_price_product' => $maxPriceRecord
                ? (int) $this->vatService->applyTo((float) $maxPriceRecord->price, $vatRate, 0)
                : null,
            /**
             * VAT rate (percentage) included in the prices; `0` when they are net.
             *
             * @var float
             */
            'vat' => $vatRate,
        ]);
    }
}
