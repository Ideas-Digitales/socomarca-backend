<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoriesExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Categories\SuperCategoryResource;
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
            ->where('enabled', true)
            ->with(['children' => function ($query) {
                $query->where('enabled', true)
                    ->with(['children' => function ($query) {
                        $query->where('enabled', true);
                    }]);
            }])
            ->filter([], $sort, $sortDirection)
            ->get();

        return response()->json(
            SuperCategoryResource::collection($categories)
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

        return response()->json(
            SuperCategoryResource::make($categories->first())
        );
    }

    /**
     * Search categories by filters
     *
     * @param Request $request
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
            ->with(['children' => function ($query) {
                $query->where('enabled', true)
                    ->with(['children' => function ($query) {
                        $query->where('enabled', true);
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
