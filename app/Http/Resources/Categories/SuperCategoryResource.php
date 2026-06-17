<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'code' => $this->code,
            'level' => $this->level,
            'key' => $this->key,
            'categories' => CategoryResource::collection($this->whenLoaded('children')),
            'categories_count' => $this->whenLoaded('children')->count(),
            'products_count' => $this->products_count ?? ($this->products ? $this->products->count() : 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
