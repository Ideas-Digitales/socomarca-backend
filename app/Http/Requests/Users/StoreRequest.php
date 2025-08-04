<?php

namespace App\Http\Requests\Users;

use App\Rules\ValidateRut;
use Illuminate\Foundation\Http\FormRequest;
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
            'email' => 'bail|required|email|unique:users,email|max:255',
            'password' => ['bail', 'required', 'confirmed', Password::min(8)->letters()],
            'phone' => 'bail|required|string|max:15',
            'rut' => ['bail', 'required', 'string', 'max:12', 'unique:users,rut', new ValidateRut],
            'business_name' => 'bail|required|string|max:255',
            'is_active' => 'bail|required|boolean',
            'roles' => 'bail|sometimes|array',
            'roles.*' => 'bail|string|exists:roles,name',
        ];
    }
}
