<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoriesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\ShowRequest;
use App\Http\Resources\Categories\CategoryCollection;
use App\Http\Resources\Categories\CategoryHierarchyResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->input('sort');
        $sortDirection = $request->input('sort_direction', 'asc');

        $categories = Category::where('level', 1)
            ->filter([
                [
                    'field' => 'enabled',
                    'operator' => '=',
                    'value' => 'true',
                ]
            ], $sort, $sortDirection)
            ->get();

        return response()->json(
            CategoryHierarchyResource::collection($categories)
        );
    }

    public function show($id)
    {
        if (!Category::find($id))
        {
            return response()->json(
            [
                'message' => 'Category not found.',
            ], 404);
        }

        $categories = Category::where('id', $id)->get();

        $data = new CategoryCollection($categories);

        return response()->json($data[0]);
    }

    /**
     * Search categories by filters
     *
     * @param Request $request
     *
     * @return CategoryCollection
     */
    public function search(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->input('filters', []);
        $filters = array_merge($filters,[
                [
                    'field' => 'enabled',
                    'operator' => '=',
                    'value' => 'true',
                ]
        ] );
        $sort = $request->input('sort');
        $sortDirection = $request->input('sort_direction', 'asc');

        $result = Category::withCount(['subcategories', 'products'])
            ->filter($filters, $sort, $sortDirection)
            ->paginate($perPage);

        return new CategoryCollection($result);
    }

    public function export(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $fileName = 'Lista_categorias' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new CategoriesExport($sort, $sortDirection), $fileName);
    }
}
