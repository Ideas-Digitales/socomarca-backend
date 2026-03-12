<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductCollection;
use App\Http\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->all();

        $products = Product::filter($filters)->paginate($perPage);

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
            'filters.category_id' => 'sometimes|integer|exists:categories,id',
            'filters.subcategory_id' => 'sometimes|integer|exists:subcategories,id',
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

        $result = Product::filter($validatedFilters)->paginate($perPage);

        // Obtener categorías y subcategorías de todos los resultados (sin paginación ni sorting)
        $filtersForExtra = array_diff_key($validatedFilters, array_flip(['sort', 'sort_direction']));
        $matchingProducts = Product::filter($filtersForExtra)
            ->select('category_id', 'subcategory_id')
            ->get();

        $categories = [];
        $subcategories = [];

        if ($matchingProducts->isNotEmpty()) {
            $categoryIds = $matchingProducts->pluck('category_id')->filter()->unique()->values();
            $subcategoryIds = $matchingProducts->pluck('subcategory_id')->filter()->unique()->values();

            $categories = Category::whereIn('id', $categoryIds)->select('id', 'name')->get()->toArray();
            $subcategories = Subcategory::whereIn('id', $subcategoryIds)->select('id', 'name')->get()->toArray();
        }

        $data = new ProductCollection($result)->additional([
            'extra' => [
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
