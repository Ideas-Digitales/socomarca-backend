<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Subcategories\SubcategoryCollection;
use App\Http\Resources\Subcategories\SubcategoryResource;
use App\Models\Subcategory;
use Dedoc\Scramble\Attributes\Group;

#[Group('Categories', weight: 5)]
class SubcategoryController extends Controller
{
    /**
     * List subcategories
     *
     * Lists every subcategory with its parent category. Not paginated. These come from the legacy
     * subcategories table, which the Random ERP sync does not fill: the synced subcategories are the
     * third level of the category list.
     *
     * @return SubcategoryCollection
     */
    public function index()
    {
        $subcategories = Subcategory::with('category')->get();

        $data = new SubcategoryCollection($subcategories);

        return $data;
    }

    /**
     * Show a subcategory
     *
     * Returns a subcategory of the legacy subcategories table with its parent category.
     *
     * @throws \Throwable
     */
    public function show(Subcategory $subcategory): \Illuminate\Http\Resources\Json\JsonResource
    {
        return $subcategory
            ->load('category')
            ->toResource(SubcategoryResource::class);
    }
}
