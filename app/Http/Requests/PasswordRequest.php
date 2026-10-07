<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class PasswordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * The user is null when the email does not belong to exactly one active user.
     */
    protected function passedValidation()
    {
        $this->merge(['user' => User::findActiveByLoginEmail($this->input('email'))]);
    }
}
