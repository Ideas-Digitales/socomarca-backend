<?php

namespace App\Http\Requests\Users;

use App\Models\User;
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
        /** @var User $user */
        $user = $this->route('user');
        $method = strtolower($this->method());
        $required = $method === 'put' ? 'required' : 'sometimes';

        // The data of synced users comes from Random and is overwritten by the sync.
        if ($user->isSyncedFromRandom()) {
            return [
                'name' => 'prohibited',
                'email' => 'prohibited',
                'phone' => 'prohibited',
                'is_active' => $required . '|boolean',
                'password' => 'prohibited',
                'roles' => 'prohibited',
                'fcm_token' => ['nullable','string','max:1000'],
            ];
        }

        return [
            /** Prohibited for users synced from Random ERP. */
            'name' => $required . '|string|max:255',
            /**
             * Stored lowercase; must not belong to another user. Prohibited for users synced from Random ERP.
             *
             * @example juan.perez@socomarca.cl
             */
            'email' => $required . '|email|unique:users,email,' . $user->id,
            /**
             * Prohibited for users synced from Random ERP.
             *
             * @var string|null
             */
            'phone' => $required . '|nullable|string|max:20',
            /** Inactive users cannot log in; deactivating a user revokes its tokens. */
            'is_active' => $required . '|boolean',
            /**
             * New password: at least 8 characters with letters. Send it again as `password_confirmation`.
             * Prohibited for users synced from Random ERP.
             */
            'password' => [$required, 'bail', 'confirmed', Password::min(8)->letters()],
            /**
             * Role names, replacing the current ones (an empty list keeps them). Prohibited for users synced
             * from Random ERP.
             *
             * @var list<string>
             * @example ["supervisor"]
             */
            'roles' => "bail|$required|array",
            /** Existing role name other than `customer`. */
            'roles.*' => ['bail', 'string', 'exists:roles,name', Rule::notIn(['customer'])],
            /** Firebase Cloud Messaging token of the user's device. */
            'fcm_token' => ['nullable','string','max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
