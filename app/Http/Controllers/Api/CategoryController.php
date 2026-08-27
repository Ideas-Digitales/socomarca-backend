<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoriesExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Categories\CategoryListResource;
use App\Http\Resources\Categories\SuperCategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CategoryController extends Controller
{
    /**
     * Build the constraint keeping only categories that hold at least one active product
     * with a price visible to the current user.
     *
     * Reused at every level of the category tree (supercategory, category, subcategory).
     * A disabled product is not on offer, so it must not keep its branch of the tree
     * alive either; the brand listing applies the same rule.
     *
     * @see \App\Models\Price::visibleTo()
     * @see \App\Http\Controllers\Api\BrandController::index()
     * @return \Closure A closure receiving the products query builder and constraining it
     */
    private function hasVisiblePrices(): \Closure
    {
        return fn ($query) => $query->where('status', true)
            ->whereHas('prices', fn ($priceQuery) => $priceQuery->visibleTo());
    }

    /**
     * List the enabled supercategories that have products visible to the current user.
     *
     * Each supercategory is returned with its enabled children and subcategories, all
     * filtered the same way, plus their product counts.
     *
     * @param Request $request Accepts optional 'sort' and 'sort_direction' inputs
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $sort = $request->input('sort');
        $sortDirection = $request->input('sort_direction', 'asc');

        $categories = Category::where('level', 1)
            ->where('enabled', true)
            ->whereHas('productsBySupercategory', $this->hasVisiblePrices())
            ->withCount(['productsBySupercategory' => $this->hasVisiblePrices()])
            ->with(['children' => function ($query) {
                $query->where('enabled', true)
                    ->whereHas('products', $this->hasVisiblePrices())
                    ->withCount(['products' => $this->hasVisiblePrices()])
                    ->with(['children' => function ($query) {
                        $query
                            ->where('enabled', true)
                            ->whereHas('productsBySubcategory', $this->hasVisiblePrices())
                            ->withCount(['productsBySubcategory' => $this->hasVisiblePrices()]);
                    }]);
            }])
            ->filter([], $sort, $sortDirection)
            ->get();

        return response()->json(
            SuperCategoryResource::collection($categories)
        );
    }

    /**
     * List every category as a flat collection, one row per level.
     *
     * Es el listado que consume la tabla del panel de administración, y espeja
     * exactamente el universo del Excel (CategoriesExport usa Category::all()):
     * incluye los tres niveles, las categorías deshabilitadas y las que no tienen
     * productos. index() no sirve para eso porque devuelve un árbol recortado a lo
     * que el cliente puede comprar.
     *
     * @see \App\Exports\CategoriesExport
     * @param Request $request Accepts optional 'sort' and 'sort_direction' inputs
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $sort = $request->input('sort', 'id');
        $sortDirection = $request->input('sort_direction', 'asc');

        $categories = Category::withCount([
            'products',
            'productsBySupercategory',
            'productsBySubcategory',
        ])
            ->filter([], $sort, $sortDirection)
            ->get();

        return response()->json(
            CategoryListResource::collection($categories)
        );
    }

    /**
     * Show a single category.
     *
     * @param int|string $id The category identifier
     * @return \Illuminate\Http\JsonResponse 404 when the category does not exist
     */
    public function show($id)
    {
        if (!Category::find($id)) {
            return response()->json(
                [
                    'message' => 'Category not found.',
                ],
                404
            );
        }

        $categories = Category::where('id', $id)->get();

        return response()->json(
            SuperCategoryResource::make($categories->first())
        );
    }

    /**
     * Search categories by filters.
     *
     * Same visibility rules as index(): only enabled categories holding products with a
     * price visible to the current user are returned.
     *
     * @param Request $request Accepts optional 'filters', 'sort' and 'sort_direction' inputs
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $filters = $request->input('filters', []);
        $filters = array_merge($filters, [
            [
                'field' => 'enabled',
                'operator' => '=',
                'value' => 'true',
            ]
        ]);
        $sort = $request->input('sort');
        $sortDirection = $request->input('sort_direction', 'asc');

        $categories = Category::where('level', 1)
            ->where('enabled', true)
            ->whereHas('productsBySupercategory', $this->hasVisiblePrices())
            ->with(['children' => function ($query) {
                $query->where('enabled', true)
                    ->whereHas('products', $this->hasVisiblePrices())
                    ->with(['children' => function ($query) {
                        $query->where('enabled', true)
                            ->whereHas('productsBySubcategory', $this->hasVisiblePrices());
                    }]);
            }])
            ->filter($filters, $sort, $sortDirection)
            ->get();


        return response()->json(
            SuperCategoryResource::collection($categories)
        );
    }

    public function export(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $fileName = 'Lista_categorias' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new CategoriesExport($sort, $sortDirection), $fileName);
    }
}
