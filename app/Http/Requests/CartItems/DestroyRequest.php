<?php

namespace App\Http\Requests\CartItems;

use App\Rules\DestroyProductItemQuantityValidator;
use Illuminate\Foundation\Http\FormRequest;

class DestroyRequest extends FormRequest
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
            /** @var int */
            'product_id' => 'required',
            /**
             * Sale unit of the cart item.
             *
             * @var string
             */
            'unit' => 'required',
            /**
             * Quantity to remove. It cannot exceed the quantity of the cart item.
             *
             * @var int
             */
            'quantity' => [
                'bail',
                'required',
                new DestroyProductItemQuantityValidator(
                    $this->input('product_id'),
                    $this->input('unit', '')
                )
            ]
        ];
    }
}
