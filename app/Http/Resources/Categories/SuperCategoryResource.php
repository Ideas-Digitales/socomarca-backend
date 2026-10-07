<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Category
 */
class SuperCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            /** Random ERP code. */
            'code' => $this->code,
            /**
             * `1` supercategory, `2` category, `3` subcategory.
             *
             * @var 1|2|3
             */
            'level' => $this->level,
            /**
             * Random ERP key: the codes of the category and its parents, separated by `/`.
             *
             * @example 01
             */
            'key' => $this->key,
            /** Child categories (level 2). Only in the category tree. */
            'categories' => CategoryResource::collection($this->whenLoaded('children')),
            /** @var int */
            'categories_count' => $this->whenLoaded('children')->count(),
            /**
             * Active products with a price visible to the user. `0` outside the category list.
             *
             * @var int
             */
            'products_count' => $this->products_by_supercategory_count ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
