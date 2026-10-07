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
            'name' => $required . '|string|max:255',
            'email' => $required . '|email|unique:users,email,' . $user->id,
            'phone' => $required . '|nullable|string|max:20',
            'is_active' => $required . '|boolean',
            'password' => [$required, 'bail', 'confirmed', Password::min(8)->letters()],
            'roles' => "bail|$required|array",
            'roles.*' => ['bail', 'string', 'exists:roles,name', Rule::notIn(['customer'])],
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
