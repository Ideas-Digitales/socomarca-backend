<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductCollection;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Data\ProductQueryService;
use App\Services\VatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(private VatService $vatService) {}

    /**
     * List products with the prices of the price lists the user may see.
     *
     * Query param "vat=true" returns prices including VAT; omit it (or
     * "vat=false") keeps prices net. The answer indicates in "vat" whether the
     * prices come with VAT and at what rate.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $this->applyVatToPriceFilters($request, $request->all());

        $service = new ProductQueryService($filters);
        $products = $service->getPaginatedProductsWithAllowedPrices($perPage);

        return (new ProductCollection($products))
            ->additional(['vat' => $this->vatMeta($request)]);
    }

    /**
     * Show a single product.
     *
     * Accepts the same "vat=true" parameter from the listing to return prices
     * with VAT included.
     */
    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Search products by filters
     *
     * Accepts the same parameter "vat=true" from the listing: the prices in the response
     * They come with VAT included and the range of "filters.price" is interpreted with VAT.
     *
     * @return ProductCollection
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vat' => 'sometimes|boolean',
            'filters' => 'required|array',
            'filters.price' => 'required|array',
            'filters.price.min' => 'required|numeric|min:0',
            'filters.price.max' => 'required|numeric|gt:filters.price.min',
            'filters.price.unit' => 'sometimes|string|max:10',
            'filters.supercategory_id' => 'sometimes|array',
            'filters.supercategory_id.*' => 'integer|exists:categories,id',
            'filters.category_id' => 'sometimes|array',
            'filters.category_id.*' => 'integer|exists:categories,id',
            'filters.subcategory_id' => 'sometimes|array',
            'filters.subcategory_id.*' => 'integer|exists:categories,id',
            'filters.brand_id' => 'sometimes|array',
            'filters.brand_id.*' => 'integer|exists:brands,id',
            'filters.sku' => 'sometimes|string|max:255',
            'filters.name' => 'sometimes|string|max:255',
            'filters.is_favorite' => 'sometimes|boolean',
            'filters.sort' => 'sometimes|string|in:price,stock,category_name,id,name,created_at,updated_at',
            'filters.sort_direction' => 'sometimes|string|in:asc,desc',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'invalid data search.', 'errors' => $validator->errors()], 422);
        }

        $validatedFilters = $validator->validated()['filters'];
        $perPage = $request->input('per_page', 20);

        $service = new ProductQueryService(
            $this->applyVatToPriceFilters($request, $validatedFilters)
        );
        $result = $service->getPaginatedProductsWithAllowedPrices($perPage);
        $categories = $service->getMatchingCategories();
        $brands = $service->getMatchingBrands();

        return (new ProductCollection($result))->additional([
            'extra' => $categories + ['brands' => $brands],
            'vat' => $this->vatMeta($request),
            'filters' => [
                'min_price' => $validatedFilters['price']['min'],
                'max_price' => $validatedFilters['price']['max'],
            ],
        ]);
    }

    /**
     * Describe how VAT was applied to the prices of the response.
     *
     * Lets the client know both whether the prices it received include VAT and the
     * rate behind them, without having to hit the settings endpoint.
     *
     * @return array{included: bool, rate: float}
     */
    private function vatMeta(Request $request): array
    {
        $included = $request->boolean('vat');

        return [
            'included' => $included,
            'rate' => $included ? $this->vatService->rate() : 0.0,
        ];
    }

    /**
     * Translate a VAT-inclusive price range back to the net prices stored in the database.
     *
     * With "vat=true" the client both sees and filters by prices with VAT, so the
     * bounds of its slider arrive gross and would otherwise exclude products whose
     * net price is inside the range the customer picked.
     *
     * @param array $filters Filters as they arrived from the request
     * @return array Filters with a net price range
     */
    private function applyVatToPriceFilters(Request $request, array $filters): array
    {
        if (! $request->boolean('vat') || ! isset($filters['price'])) {
            return $filters;
        }

        $rate = $this->vatService->rate();

        foreach (['min', 'max'] as $bound) {
            if (isset($filters['price'][$bound])) {
                $filters['price'][$bound] = $this->vatService->netFrom(
                    (float) $filters['price'][$bound],
                    $rate
                );
            }
        }

        return $filters;
    }
}
