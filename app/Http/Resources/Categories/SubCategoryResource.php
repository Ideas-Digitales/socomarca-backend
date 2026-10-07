<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Category
 */
class SubCategoryResource extends JsonResource
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
            /** @var 3 */
            'level' => $this->level,
            /**
             * Random ERP key: the codes of the subcategory and its parents, separated by `/`.
             *
             * @example 01/02/03
             */
            'key' => $this->key,
            /** @var \App\Models\Category */
            'parent' => $this->whenLoaded('parent'),
            /**
             * Active products with a price visible to the user. `0` outside the category list.
             *
             * @var int
             */
            'products_count' => $this->products_by_subcategory_count ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
