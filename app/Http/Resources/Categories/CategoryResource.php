<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            /** @var 2 */
            'level' => $this->level,
            /**
             * Random ERP key: the codes of the category and its parents, separated by `/`.
             *
             * @example 01/02
             */
            'key' => $this->key,
            /** Child subcategories (level 3). */
            'subcategories' => SubCategoryResource::collection($this->whenLoaded('children')),
            'subcategories_count' => $this->whenLoaded('children')->count(),
            /**
             * Active products with a price visible to the user.
             *
             * @var int
             */
            'products_count' => $this->products_count ?? ($this->products ? $this->products->count() : 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
