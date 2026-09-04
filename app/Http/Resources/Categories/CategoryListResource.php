<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single category inside the admin's flat listing.
 *
 * Unlike SuperCategoryResource it nests nothing: every level is a row of its own.
 * The product count comes from whichever relation matches the level, because a
 * product points at its supercategory, category and subcategory through three
 * separate columns.
 *
 * @see \App\Http\Controllers\Api\CategoryController::index()
 */
class CategoryListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'level' => $this->level,
            'key' => $this->key,
            'enabled' => $this->enabled,
            'products_count' => $this->productsCountForLevel(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The count coming from the relation that applies to this category's level.
     */
    private function productsCountForLevel(): int
    {
        return match ((int) $this->level) {
            1 => (int) ($this->products_by_supercategory_count ?? 0),
            3 => (int) ($this->products_by_subcategory_count ?? 0),
            default => (int) ($this->products_count ?? 0),
        };
    }
}
