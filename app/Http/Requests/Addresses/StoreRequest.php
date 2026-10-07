<?php

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
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
        return
        [
            /**
             * Synced addresses are created by the Random sync only.
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
            'postal_code' => 'bail|nullable|integer',
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
    }
}
