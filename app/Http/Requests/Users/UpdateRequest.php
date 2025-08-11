<?php

namespace App\Http\Requests\Users;

use App\Rules\ValidateRut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
        $userId = $this->segment(3);
        $method = strtolower($this->method());
        $required = $method === 'put' ? 'required' : 'sometimes';

        return [
            'name' => $required . '|string|max:255',
            'email' => $required . '|email|unique:users,email,' . $userId,
            'phone' => $required . '|nullable|string|max:20',
            'is_active' => $required . '|boolean',
            'password' => [$required, 'bail', 'confirmed', Password::min(8)->letters()],
            'roles' => "bail|$required|array",
            'roles.*' => 'bail|string|exists:roles,name',
        ];
    }

    protected function prepareForValidation()
    {
    }
}
