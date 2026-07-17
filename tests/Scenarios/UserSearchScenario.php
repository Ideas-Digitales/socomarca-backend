<?php

namespace Tests\Scenarios;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserSearchScenario
{
    public function __construct(
        public User $adminUser,
        public User $userWithoutPermissions,
        public array $listJsonStructure,
    ) {}

    public static function make(): UserSearchScenario
    {
        // Crear permiso directamente
        $manageUsersPermission = Permission::firstOrCreate(['name' => 'manage-users']);

        // Crear roles y asignar permisos
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($manageUsersPermission);

        // Usuario admin con permisos
        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $userWithoutPermissions = User::factory()->create();

        $userListJsonStructure = [
            'data' => [
                [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'rut',
                    'business_name',
                    'is_active',
                    'last_login',
                    'roles',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'path',
                'per_page',
                'to',
                'total',
                'links' => [
                    ['url', 'label', 'active']
                ],
            ]
        ];

        return new UserSearchScenario($adminUser, $userWithoutPermissions, $userListJsonStructure);
    }
}
