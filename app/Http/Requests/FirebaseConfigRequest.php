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
            /** @example service_account */
            'type' => ['required', 'string'],
            /** @example socomarca-app */
            'project_id' => ['required', 'string'],
            /** PEM private key of the service account. */
            'private_key' => ['required', 'string'],
            /** @example firebase-adminsdk-abc12@socomarca-app.iam.gserviceaccount.com */
            'client_email' => ['required', 'email'],
        ];
    }
}
