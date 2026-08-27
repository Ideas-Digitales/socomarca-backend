<?php

namespace App\Http\Resources\Categories;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una categoría dentro del listado plano del panel de administración.
 *
 * A diferencia de SuperCategoryResource, no anida hijos: cada nivel es una fila
 * propia. El conteo de productos sale de la relación que corresponde al nivel,
 * porque un producto apunta a su supercategoría, categoría y subcategoría con
 * tres columnas distintas.
 *
 * @see \App\Http\Controllers\Api\CategoryController::all()
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
     * El conteo de la relación que aplica al nivel de esta categoría.
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
