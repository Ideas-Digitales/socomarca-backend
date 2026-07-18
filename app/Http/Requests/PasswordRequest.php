<?php

namespace App\Http\Requests;

use App\Models\User;

class PasswordRequest extends AuthRutRequest
{
    protected function passedValidation()
    {
        $user = User::where('rut', $this->input('rut'))->first();
        $this->merge(['user' => $user]);
    }
}
