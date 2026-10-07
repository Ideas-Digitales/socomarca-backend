<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchUsersRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

#[Group('Roles and permissions', 'Roles, their permissions and the roles and permissions of each user.', weight: 2)]
class RoleController extends Controller
{
    /**
     * List roles
     *
     * Every role with the names of its permissions.
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();

        $result = $roles->map(function($role) {
            return [
                /** @var int */
                'id' => $role->id,
                /** @var string */
                'name' => $role->name,
                /** @var list<string> */
                'permissions' => $role->permissions->pluck('name'),
                /**
                 * @var string|null
                 * @format date-time
                 */
                'created_at' => $role->created_at,
                /**
                 * @var string|null
                 * @format date-time
                 */
                'updated_at' => $role->updated_at,
            ];
        });

        return response()->json($result);
    }

    /**
     * List roles with their users
     *
     * Every role with the users that have it.
     */
    public function rolesWithUsers()
    {
        $roles = Role::all();

        $result = $roles->map(function($role) {
        // Obtén los usuarios que tienen este rol
        $users = User::role($role->name)->get(['id', 'name', 'email']);

        return [
                /** Role name. */
                'role' => $role->name,
                /** @var list<array{id: int, name: string, email: string}> */
                'users' => $users,
            ];
        });

        return response()->json($result);
    }


    /**
     * Show the roles of a user
     *
     * Roles of the user and all its permissions, through its roles or assigned directly.
     */
    public function userRoles(User $user)
    {
        
        $roles = $user->getRoleNames();
        $permissions = $user->getAllPermissions()->pluck('name');
        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            /** @var list<string> */
            'roles' => $roles,
            /** @var list<string> */
            'permissions' => $permissions,
        ]);
    }


}
