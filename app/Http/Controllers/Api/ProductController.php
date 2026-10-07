<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductCollection;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Data\ProductQueryService;
use App\Services\VatService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Products', 'Catalog synced from Random ERP, with the prices of the price lists each user may see, and product image uploads.', weight: 7)]
class ProductController extends Controller
{
    public function __construct(private VatService $vatService) {}

    /**
     * List products
     *
     * Returns one row per product, price list and unit: only active products with an active, in-stock
     * price (zero prices are hidden unless enabled in the configuration). Customers only get rows of
     * their own price lists; users who can read all products get every list.
     *
     * Also accepts the filters below as query parameters. They are not validated, so prefer
     * Search products to filter.
     */
    #[QueryParameter('vat', 'Return prices with VAT included.', type: 'bool', default: false)]
    #[QueryParameter('per_page', 'Items per page.', type: 'int', default: 20)]
    #[QueryParameter('name', 'Search text, fuzzy-matched against the name and partially matched against the SKU. Without `sort`, results are ordered by similarity.', type: 'string')]
    #[QueryParameter('sku', 'Exact SKU.', type: 'string', example: 'SKU-12345')]
    #[QueryParameter('supercategory_id', 'Supercategory IDs.', type: 'list<int>')]
    #[QueryParameter('category_id', 'Category IDs.', type: 'list<int>')]
    #[QueryParameter('subcategory_id', 'Subcategory IDs.', type: 'list<int>')]
    #[QueryParameter('brand_id', 'Brand IDs.', type: 'list<int>')]
    #[QueryParameter('sort', 'Sort field. `price` and `stock` sort by the price of each row.', type: "'price'|'stock'|'category_name'|'id'|'name'|'created_at'|'updated_at'")]
    #[QueryParameter('sort_direction', 'Sort direction.', type: "'asc'|'desc'", default: 'asc')]
    #[Response(200, 'Paginated product rows')]
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $this->applyVatToPriceFilters($request, $request->all());

        $service = new ProductQueryService($filters);
        $products = $service->getPaginatedProductsWithAllowedPrices($perPage);

        return (new ProductCollection($products))
            ->additional([
                /** How VAT was applied to the prices. */
                'vat' => $this->vatMeta($request),
            ]);
    }

    /**
     * Show a product
     *
     * Returns the product with all its prices, of every price list and unit, regardless of the user's
     * price lists and of whether the price is active.
     */
    #[QueryParameter('vat', 'Return prices with VAT included.', type: 'bool', default: false)]
    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Search products
     *
     * Same rows and price-list scoping as List products, narrowed by `filters`. With `vat=true` the
     * prices include VAT and `filters.price` is read as VAT-inclusive.
     *
     * `extra` lists the supercategories, categories, subcategories and brands present in the matching
     * products, to build the filter sidebar. Brands ignore the `brand_id` filter, so the other brands
     * of the search stay available.
     */
    #[BodyParameter('per_page', 'Items per page.', type: 'int', default: 20)]
    #[Response(200, 'Paginated product rows')]
    #[Response(422, 'Validation error', type: 'array{message: "invalid data search.", errors: array<string, list<string>>}')]
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            /**
             * Return prices with VAT included and read `filters.price` as VAT-inclusive.
             *
             * @default false
             */
            'vat' => 'sometimes|boolean',
            'filters' => 'required|array',
            /** Unit price range. */
            'filters.price' => 'required|array',
            /** @example 0 */
            'filters.price.min' => 'required|numeric|min:0',
            /**
             * Must be greater than `min`.
             *
             * @example 50000
             */
            'filters.price.max' => 'required|numeric|gt:filters.price.min',
            /**
             * Only prices of this sale unit (Random ERP unit code).
             *
             * @example UN
             */
            'filters.price.unit' => 'sometimes|string|max:10',
            'filters.supercategory_id' => 'sometimes|array',
            'filters.supercategory_id.*' => 'integer|exists:categories,id',
            'filters.category_id' => 'sometimes|array',
            'filters.category_id.*' => 'integer|exists:categories,id',
            'filters.subcategory_id' => 'sometimes|array',
            'filters.subcategory_id.*' => 'integer|exists:categories,id',
            'filters.brand_id' => 'sometimes|array',
            'filters.brand_id.*' => 'integer|exists:brands,id',
            /** Exact SKU. */
            'filters.sku' => 'sometimes|string|max:255',
            /** Search text, fuzzy-matched against the name and partially matched against the SKU. Without `sort`, results are ordered by similarity. */
            'filters.name' => 'sometimes|string|max:255',
            /** `true` returns only products in the user's favorite lists; `false`, only the others. */
            'filters.is_favorite' => 'sometimes|boolean',
            /** Sort field. `price` and `stock` sort by the price of each row. */
            'filters.sort' => 'sometimes|string|in:price,stock,category_name,id,name,created_at,updated_at',
            /** @default asc */
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
            /**
             * Facets of the matching products, for the filter sidebar.
             *
             * @var array{supercategories: list<array{id: int, name: string}>, categories: list<array{id: int, name: string}>, subcategories: list<array{id: int, name: string}>, brands: list<array{id: int, name: string}>}
             */
            'extra' => $categories + ['brands' => $brands],
            /** How VAT was applied to the prices. */
            'vat' => $this->vatMeta($request),
            /** Price range of the request, as sent. */
            'filters' => [
                /** @var float */
                'min_price' => $validatedFilters['price']['min'],
                /** @var float */
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
            /** Whether the prices include VAT (`vat=true`). */
            'included' => $included,
            /** VAT rate (percentage) included in the prices; `0` when they are net. */
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
