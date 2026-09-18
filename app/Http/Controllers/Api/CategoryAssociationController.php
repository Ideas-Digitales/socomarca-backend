<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Siteinfo;
use App\Services\CategoryAssociationService;
use Illuminate\Http\Request;

class CategoryAssociationController extends Controller
{
    /**
     * Show whether the strict category association is in force
     *
     * @param CategoryAssociationService $categoryAssociationService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(CategoryAssociationService $categoryAssociationService)
    {
        return response()->json([
            CategoryAssociationService::FLAG => $categoryAssociationService->strictEnabled(),
        ]);
    }

    /**
     * Turn the strict category association on or off
     *
     * While on, a product whose supercategory is not the parent of its category (or whose
     * category is not the parent of its subcategory) stops holding up nodes of the
     * category tree and stops appearing in the category facets of the product search. The
     * product itself stays on sale and keeps its row in the listing and in the search.
     *
     * It takes effect on the next request, without a resync of the Random data.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            CategoryAssociationService::FLAG => 'required|boolean',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => CategoryAssociationService::SETTINGS_KEY],
            [
                'value' => [
                    CategoryAssociationService::FLAG => (bool) $data[CategoryAssociationService::FLAG],
                ],
                'content' => 'Oculta del árbol de categorías y de los filtros de búsqueda los productos cuya categoría no cuelga de su supercategoría',
            ]
        );

        return response()->json(['message' => 'Configuración actualizada correctamente']);
    }
}
