<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = config('authorization.permissions');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $roles = config('authorization.roles');

        foreach ($roles as $roleName => $_permissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($_permissions);
        }
    }
}
