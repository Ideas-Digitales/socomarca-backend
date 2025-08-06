<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Subcategories\SubcategoryCollection;
use App\Http\Resources\Subcategories\SubcategoryResource;
use App\Models\Subcategory;

class SubcategoryController extends Controller
{
    /**
     * @return SubcategoryCollection
     */
    public function index()
    {
        $subcategories = Subcategory::with('category')->get();

        $data = new SubcategoryCollection($subcategories);

        return $data;
    }

    /**
     * @throws \Throwable
     */
    public function show(Subcategory $subcategory): \Illuminate\Http\Resources\Json\JsonResource
    {
        return $subcategory
            ->load('category')
            ->toResource(SubcategoryResource::class);
    }
}
