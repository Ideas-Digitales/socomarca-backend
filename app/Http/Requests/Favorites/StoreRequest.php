<?php

namespace App\Http\Requests\Favorites;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $favoriteListId = $this->input('favorite_list_id');
        $user = $this->user();

        $favoriteList = \App\Models\FavoriteList::find($favoriteListId);

        return $favoriteList
            && $favoriteList->user_id === $user->id
            && $user->can('create', \App\Models\Favorite::class);
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
            'favorite_list_id' => 'required',
            'product_id' => 'required|exists:products,id',
            'unit' => [
                'required',
                'string',
                new \App\Rules\ProductHasUnit($this->input('product_id')),
            ],
        ];
    }

}
