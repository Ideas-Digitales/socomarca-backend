<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoriesExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Categories\CategoryListResource;
use App\Http\Resources\Categories\SuperCategoryResource;
use App\Models\Category;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

#[Group('Categories', 'Browse the product category tree synced from Random ERP: supercategories, categories and subcategories.', weight: 5)]
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
     * List categories
     *
     * With `structure=nested` (the default) returns the storefront tree: the enabled supercategories that
     * have active products with a price visible to the user (customers only see their price lists), each
     * with its categories and subcategories filtered the same way, and the count of those products.
     *
     * With `structure=flat` returns every category of the three levels as its own row, including disabled
     * ones and those without products, for the administration. It also requires the `read-all-reports`
     * permission (403 otherwise).
     */
    #[QueryParameter('sort', 'Field to sort by. In the nested tree it sorts the supercategories only. Unknown fields are ignored.', type: "'id'|'name'|'description'|'code'|'level'|'created_at'|'updated_at'")]
    #[QueryParameter('sort_direction', type: "'asc'|'desc'", default: 'asc')]
    public function index(Request $request)
    {
        $request->validate([
            /** `nested` returns the storefront tree, `flat` one row per category. */
            'structure' => 'sometimes|string|in:nested,flat',
        ]);

        if ($request->input('structure') === 'flat') {
            return $this->flatIndex($request);
        }

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
     * Backs the admin table, and mirrors exactly what the Excel export covers
     * (CategoriesExport uses Category::all()): the three levels, the disabled
     * categories and the ones holding no product. The nested structure cannot serve
     * that table, since it is trimmed down to what the current user may buy.
     *
     * Because it exposes the same rows as the export, it requires the export's
     * permission: 'read-all-categories' guards the route, but customers hold it too,
     * and they have no business seeing disabled or empty categories.
     *
     * @see \App\Exports\CategoriesExport
     * @param Request $request Accepts optional 'sort' and 'sort_direction' inputs
     * @return \Illuminate\Http\JsonResponse 403 when the user may not read reports
     */
    private function flatIndex(Request $request)
    {
        if (! $request->user()?->can('read-all-reports')) {
            abort(403, 'This action is unauthorized.');
        }

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
     * Show a category
     *
     * Returns the category of any level, without its children.
     *
     * @param int $id The category ID.
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
     * Search categories
     *
     * Returns the same tree as the nested category list, keeping only the supercategories that match
     * `filters`. Product counts are not computed here: use the category list for them.
     */
    #[BodyParameter('filters', 'Conditions on the supercategories, all of which must match. `field` is `name` or `description` (operators `=`, `!=`, `LIKE`, `ILIKE`, `NOT LIKE` and `fulltext`, a trigram similarity search that also orders by similarity), `code` (the same except `fulltext`), `level` (`=`, `!=`, `>`, `<`, `>=`, `<=`) or `enabled` (`=`, `!=`). Unknown fields and operators are ignored. The optional `sort` (`ASC` or `DESC`) also orders by the field.', type: 'list<array{field: string, operator: string, value: mixed, sort?: string}>', example: [['field' => 'name', 'operator' => 'ILIKE', 'value' => '%bebida%']])]
    #[BodyParameter('sort', 'Field to sort the supercategories by. Unknown fields are ignored.', type: "'id'|'name'|'description'|'code'|'level'|'created_at'|'updated_at'")]
    #[BodyParameter('sort_direction', type: "'asc'|'desc'", default: 'asc')]
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

    /**
     * Export categories
     *
     * Downloads an Excel file with every category of the three levels, including disabled ones and those
     * without products.
     *
     * @response \Symfony\Component\HttpFoundation\BinaryFileResponse<string, 200, array{"Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}, null>
     */
    #[QueryParameter('sort', 'Currently ignored: rows follow the database order.', type: 'string', default: 'name')]
    #[QueryParameter('sort_direction', 'Currently ignored.', type: 'string', default: 'asc')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function export(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $fileName = 'Lista_categorias' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new CategoriesExport($sort, $sortDirection), $fileName);
    }
}
