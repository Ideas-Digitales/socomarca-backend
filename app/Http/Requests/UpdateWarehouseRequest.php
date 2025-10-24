<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('warehouse'));
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'priority' => 'sometimes|integer|min:1|max:999',
            'is_active' => 'sometimes|boolean',
            'phone' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email|max:255',
            'manager_name' => 'sometimes|nullable|string|max:255',
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'priority.min' => 'La prioridad debe ser al menos 1.',
            'priority.max' => 'La prioridad no puede ser mayor a 999.',
            'email.email' => 'El email debe tener un formato válido.',
        ];
    }
}