<?php

namespace App\Http\Requests\CartItems;

use App\Rules\ProductMustHavePrice;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('CartItemStoreRequest')]
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
        return [
            /** Product to add. It must have an active price for `unit`. */
            'product_id'    => [
                'bail',
                'required',
                'exists:products,id',
                new ProductMustHavePrice($this->input('unit', ''))
            ],
            /** Quantity to add to the cart item. */
            'quantity'      => 'bail|required|integer|min:1|max:99',
            /** Sale unit of the product, as named in its prices (Random ERP unit name). */
            'unit'          => 'required|string|max:10',
        ];
    }
}
