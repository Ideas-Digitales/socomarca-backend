<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryHierarchyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subcategories = $this->subcategories()
            ->where('level', 2)
            ->get()
            ->map(function ($subcategory) {
                $children = $subcategory->category->subcategories()
                    ->where('level', 3)
                    ->where('key', 'LIKE', $subcategory->key . '/%')
                    ->get()
                    ->map(fn ($child) => [
                        'id' => $child->id,
                        'name' => $child->name,
                        'code' => $child->code,
                        'level' => $child->level,
                        'key' => $child->key,
                        'products_count' => $child->products()->count(),
                    ])
                    ->toArray();

                return [
                    'id' => $subcategory->id,
                    'name' => $subcategory->name,
                    'code' => $subcategory->code,
                    'level' => $subcategory->level,
                    'key' => $subcategory->key,
                    'products_count' => $subcategory->products()->count(),
                    'children' => $children,
                ];
            })
            ->toArray();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'level' => $this->level,
            'key' => $this->key,
            'products_count' => $this->products()->count(),
            'children' => $subcategories,
        ];
    }
}
