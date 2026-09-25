<?php

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        switch (strtolower($this->method())) {
            case 'put':
                return [
                    // Branch addresses are managed by Random sync only.
                    'branch_id' => 'bail|prohibited',
                    'address_line1' => 'bail|required|string',
                    'address_line2' => 'bail|nullable|string',
                    'postal_code' => 'bail|nullable|string',
                    'is_default' => 'bail|required|boolean',
                    'type' => ['bail', 'required', Rule::in(['billing', 'shipping'])],
                    'phone' => 'bail|required|integer|digits:9',
                    'contact_name' => 'bail|required|string',
                    'municipality_id' => 'bail|required|integer|exists:municipalities,id',
                    'alias' => 'bail|required|string|max:50',
                ];

            case 'patch':
                return [
                    'branch_id' => 'bail|prohibited',
                    'address_line1' => 'sometimes|string|max:255',
                    'address_line2' => 'sometimes|nullable|string|max:255',
                    'postal_code'   => 'sometimes|nullable|string|max:20',
                    'is_default'    => 'sometimes|boolean',
                    'type'          => 'sometimes|string|max:50',
                    'contact_name'  => 'sometimes|string|max:255',
                    'municipality_id' => 'sometimes|integer|exists:municipalities,id',
                    'alias'         => 'sometimes|string|max:255',
                    'phone'         => 'sometimes|string|max:30',
                ];

            default:
                throw new \Exception('Method not allowed');
        }
    }


}
