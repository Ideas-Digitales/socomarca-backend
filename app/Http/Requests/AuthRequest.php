<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Override;

class AuthRequest extends AuthRutRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    #[Override]
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['password'] = [
            'required',
            'string',
        ];
        return $rules;
    }

    protected function passedValidation()
    {
        $user = User::where('rut', $this->input('rut'))->firstOrFail();
        $password = $this->input('password');

        $isPasswordValid = Hash::check(
            $password,
            $user->password
        );

        if (!$user || !$isPasswordValid || !$user->is_active) {
            abort(401, 'Unauthorized');
        }

        $this->merge(['auth_user' => $user]); // Authenticated user merged into request
    }
}
