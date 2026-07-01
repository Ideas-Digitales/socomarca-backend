<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductCollection;
use App\Http\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->all();

        $products = Product::byUserPrices($request->user())
            ->filter($filters)
            ->active()
            ->paginate($perPage);

        return new ProductCollection($products);
    }

    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Search products by filters
     *
     * @param Request $request
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

        $result = Product::byUserPrices($request->user())
            ->filter($validatedFilters)
            ->active()
            ->paginate($perPage);

        // Obtener categorías de todos los resultados (sin paginación ni sorting)
        $filtersForExtra = array_diff_key($validatedFilters, array_flip(['sort', 'sort_direction']));
        $matchingProducts = Product::filter($filtersForExtra)
            ->active()
            ->select('supercategory_id', 'category_id', 'subcategory_id')
            ->get();

        $supercategories = [];
        $categories = [];
        $subcategories = [];

        if ($matchingProducts->isNotEmpty()) {
            $supercategoryIds = $matchingProducts->pluck('supercategory_id')->filter()->unique()->values();
            $categoryIds = $matchingProducts->pluck('category_id')->filter()->unique()->values();
            $subcategoryIds = $matchingProducts->pluck('subcategory_id')->filter()->unique()->values();

            if ($supercategoryIds->isNotEmpty()) {
                $supercategories = Category::whereIn('id', $supercategoryIds)
                    ->where('level', 1)
                    ->select('id', 'name')
                    ->get()
                    ->toArray();
            }

            if ($categoryIds->isNotEmpty()) {
                $categories = Category::whereIn('id', $categoryIds)
                    ->where('level', 2)
                    ->select('id', 'name')
                    ->get()
                    ->toArray();
            }

            if ($subcategoryIds->isNotEmpty()) {
                $subcategories = Category::whereIn('id', $subcategoryIds)
                    ->where('level', 3)
                    ->select('id', 'name')
                    ->get()
                    ->toArray();
            }
        }

        $data = new ProductCollection($result)->additional([
            'extra' => [
                'supercategories' => $supercategories,
                'categories' => $categories,
                'subcategories' => $subcategories,
            ],
            'filters' => [
                'min_price' => $validatedFilters['price']['min'],
                'max_price' => $validatedFilters['price']['max'],
            ],
        ]);

        return $data;
    }
}
