<?php

namespace App\Http\Requests\Credentials;

use Illuminate\Foundation\Http\FormRequest;

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
        $rules = [
            'email' => []
        ];
        $user = $this->user();

        if ($user->email === null) {
            $rules['email'][] = 'required';
        } else {
            $rules['email'][] = 'sometimes';
        }

        $rules['email'][] = 'email';
        $rules['email'][] = 'unique:App\Models\User,email';
        return $rules;
    }
}
