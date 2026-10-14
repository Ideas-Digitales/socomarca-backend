<?php

namespace App\Http\Resources\Subcategories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SubcategoryCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var list<array{
             *     id: int,
             *     name: string,
             *     description: string|null,
             *     code: string|null,
             *     level: int|null,
             *     key: string|null,
             *     category: array{
             *         id: int,
             *         name: string,
             *         description: string|null,
             *         code: string,
             *         level: int,
             *         key: string,
             *         created_at: \Illuminate\Support\Carbon|null,
             *         updated_at: \Illuminate\Support\Carbon|null,
             *     },
             *     created_at: \Illuminate\Support\Carbon|null,
             *     updated_at: \Illuminate\Support\Carbon|null,
             * }>
             */
            'data' => $this->collection->map(function ($subcategory) {
                return [
                    'id' => $subcategory->id,
                    'name' => $subcategory->name,
                    'description' => $subcategory->description,
                    'code' => $subcategory->code,
                    'level' => $subcategory->level,
                    'key' => $subcategory->key,
                    'category' => [
                        'id' => $subcategory->category->id,
                        'name' => $subcategory->category->name,
                        'description' => $subcategory->category->description,
                        'code' => $subcategory->category->code,
                        'level' => $subcategory->category->level,
                        'key' => $subcategory->category->key,
                        'created_at' => $subcategory->category->created_at,
                        'updated_at' => $subcategory->category->updated_at,
                    ],
                    'created_at' => $subcategory->created_at,
                    'updated_at' => $subcategory->updated_at,
                ];
            }),
        ];
    }
}
