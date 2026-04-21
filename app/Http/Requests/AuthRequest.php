<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ValidateRut;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class AuthRequest extends FormRequest
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
            'rut' => ['required'],
            'password' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Error de validación',
            'errors' => $validator->errors()
        ], 422));
    }

    protected function passedValidation()
    {
        $user = User::where('rut', $this->input('rut'))->first();

        if (!$user) {
            abort(401, 'Usuario no encontrado');
        }

        $isPasswordValid = Hash::check(
            $this->input('password'),
            $user->password
        );

        if (!$user || !$isPasswordValid || !$user->is_active) {
            abort(401, 'Unauthorized');
        }

        $this->merge(['auth_user' => $user]); // Authenticated user merged into request
    }
}
