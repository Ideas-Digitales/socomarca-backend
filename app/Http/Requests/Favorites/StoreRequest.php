<?php

namespace App\Http\Requests\Favorites;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('FavoriteStoreRequest')]
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
             * Favorite list of the authenticated user.
             *
             * @var int
             */
            'favorite_list_id' => 'required',
            'product_id' => 'required|exists:products,id',
            /** Sale unit of the product; the product must have a price for it. */
            'unit' => [
                'required',
                'string',
                new \App\Rules\ProductHasUnit($this->input('product_id')),
            ],
        ];
    }

}
