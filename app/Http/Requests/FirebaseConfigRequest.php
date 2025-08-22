<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FirebaseConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'project_id' => ['required', 'string'],
            'private_key' => ['required', 'string'],
            'client_email' => ['required', 'email'],
        ];
    }
}