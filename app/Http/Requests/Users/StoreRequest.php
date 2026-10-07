<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Rules\ValidateRut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        
        // If no user is authenticated, deny access
        if (!$user) {
            return false;
        }
        
        // Check if trying to create admin users (admin or superadmin roles)
        $roles = $this->input('roles', []);
        $adminRoles = ['admin', 'superadmin'];
        $isCreatingAdminUser = !empty(array_intersect($roles, $adminRoles));
        
        if ($isCreatingAdminUser) {
            // Creating admin users requires create-admin-users permission
            return $user->can('create-admin-users');
        }
        
        // Creating regular users requires create-users permission
        return $user->can('create-users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return
        [
            'name' => 'bail|required|string|max:255',
            /**
             * Stored lowercase; must not belong to another user.
             *
             * @example juan.perez@socomarca.cl
             */
            'email' => 'bail|required|email|unique:users,email|max:255',
            /** At least 8 characters with letters. Send it again as `password_confirmation`. */
            'password' => ['bail', 'required', 'confirmed', Password::min(8)->letters()],
            /** @example +56912345678 */
            'phone' => 'bail|required|string|max:15',
            /**
             * Chilean RUT, with check digit.
             *
             * @example 12345678-5
             */
            'rut' => ['bail', 'required', 'string', 'max:12', new ValidateRut],
            'business_name' => 'bail|required|string|max:255',
            /** Inactive users cannot log in. */
            'is_active' => 'bail|required|boolean',
            /**
             * Role names. `admin` and `superadmin` require the `create-admin-users` permission.
             *
             * @example ["supervisor"]
             */
            'roles' => 'bail|required|array|min:1',
            /** Existing role name other than `customer`. */
            'roles.*' => ['bail', 'string', 'exists:roles,name', Rule::notIn(['customer'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
