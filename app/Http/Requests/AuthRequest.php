<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class AuthRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Login email. Customers use their commercial email (Random ERP `EMAILCOMER`).
             *
             * @example compras@cliente.cl
             */
            'email' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Unknown, inactive or duplicated emails and wrong passwords get the same response,
     * so that it is not possible to know whether an email is registered.
     */
    protected function passedValidation()
    {
        $user = User::findActiveByLoginEmail($this->input('email'));

        if ($user === null || !Hash::check($this->input('password'), $user->password)) {
            abort(401, 'Unauthorized');
        }

        $this->merge(['auth_user' => $user]); // Authenticated user merged into request
    }
}
