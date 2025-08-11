<?php

namespace App\Http\Requests\FavoritesList;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $favoriteList = \App\Models\FavoriteList::find($this->route('id'));
        return $favoriteList && $this->user()->can('update', $favoriteList);
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
            'name' => 'bail|required|string',
        ];
    }
}
