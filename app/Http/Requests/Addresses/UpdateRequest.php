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
                    /**
                     * Synced addresses are managed by the Random sync only.
                     *
                     * @ignoreParam
                     */
                    'is_synced' => 'bail|prohibited',
                    /**
                     * Street and number.
                     *
                     * @example Av. Providencia 1234
                     */
                    'address_line1' => 'bail|required|string',
                    /**
                     * Apartment, office or other details.
                     *
                     * @example Oficina 502
                     */
                    'address_line2' => 'bail|nullable|string',
                    /** @example 7500000 */
                    'postal_code' => 'bail|nullable|string',
                    /** Make it the user's default address. */
                    'is_default' => 'bail|required|boolean',
                    'type' => ['bail', 'required', Rule::in(['billing', 'shipping'])],
                    /**
                     * Contact phone, 9 digits without the country code.
                     *
                     * @example 912345678
                     */
                    'phone' => 'bail|required|integer|digits:9',
                    /** @example Juan Pérez */
                    'contact_name' => 'bail|required|string',
                    /** Municipality (comuna) ID, as listed by `GET /regions`. */
                    'municipality_id' => 'bail|required|integer|exists:municipalities,id',
                    /**
                     * Name the user gives to the address.
                     *
                     * @example Bodega central
                     */
                    'alias' => 'bail|required|string|max:50',
                ];

            case 'patch':
                return [
                    /** @ignoreParam */
                    'is_synced' => 'bail|prohibited',
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
