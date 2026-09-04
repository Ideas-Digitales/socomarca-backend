<?php

namespace Tests\Scenarios;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SettingsScenario
{
    public function __construct(
        public User $admin,
    ) {}

    /**
     * Admin user allowed to read and update the site settings.
     *
     * The content-settings permissions are not part of RolesAndPermissionsSeeder,
     * so the scenario creates them together with the role that carries them.
     */
    public static function make(): SettingsScenario
    {
        Permission::firstOrCreate(['name' => 'read-content-settings']);
        Permission::firstOrCreate(['name' => 'update-content-settings']);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo(['read-content-settings', 'update-content-settings']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return new SettingsScenario($admin);
    }
}
