<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductCollection;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Data\ProductQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->all();

        $service = new ProductQueryService($filters);
        $products = $service->getPaginatedProductsWithAllowedPrices($perPage);

        return new ProductCollection($products);
    }

    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Search products by filters
     *
     *
     * @return ProductCollection
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
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

        $service = new ProductQueryService($validatedFilters);
        $result = $service->getPaginatedProductsWithAllowedPrices($perPage);
        $categories = $service->getMatchingCategories();

        return (new ProductCollection($result))->additional([
            'extra' => $categories,
            'filters' => [
                'min_price' => $validatedFilters['price']['min'],
                'max_price' => $validatedFilters['price']['max'],
            ],
        ]);
    }
}
