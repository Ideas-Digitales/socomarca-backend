<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('read-all-warehouses');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'include' => [
                'sometimes',
                'string',
                function ($attribute, $value, $fail) {
                    $includes = explode(',', $value);
                    $includes = array_map('trim', $includes);

                    // Validar que no se incluyan ambos stock_summary y product_stock
                    if (in_array('stock_summary', $includes) && in_array('product_stock', $includes)) {
                        $fail('No se pueden incluir stock_summary y product_stock simultáneamente debido a conflictos en las consultas.');
                    }

                    // Validar includes válidos
                    $validIncludes = ['stock_summary', 'product_stock'];
                    $invalidIncludes = array_diff($includes, $validIncludes);

                    if (!empty($invalidIncludes)) {
                        $fail('Los siguientes includes no son válidos: ' . implode(', ', $invalidIncludes));
                    }
                }
            ],
            'product_id' => 'sometimes|exists:products,id',
            'unit' => 'sometimes|string|max:10',
            'with_stock_only' => 'sometimes|boolean',
            'available_only' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'include.string' => 'El parámetro include debe ser una cadena de texto.',
            'product_id.exists' => 'El producto especificado no existe.',
            'per_page.min' => 'El número de elementos por página debe ser al menos 1.',
            'per_page.max' => 'El número de elementos por página no puede ser mayor a 100.',
        ];
    }
}