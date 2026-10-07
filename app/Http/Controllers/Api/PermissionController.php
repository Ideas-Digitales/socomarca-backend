<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Group('Roles and permissions', weight: 2)]
class PermissionController extends Controller
{
    /**
     * List permissions
     *
     * Every permission that can be granted to roles.
     */
    public function index()
    {
        return Permission::all();
    }
}
