<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class ReassignUserRolesCommand extends Command
{
    protected $signature = 'permissions:sync';
    protected $description = 'Reasigna roles a todos los usuarios según lógica definida';

    public function handle()
    {
        $roles = ['superadmin', 'admin', 'supervisor', 'editor'];
        $permissions = config('authorization.permissions', []);
        $users = User::all();

        foreach ($users as $i => $user) {

            if ($i < count($roles)) {
                $user->syncRoles([$roles[$i]]);
                $user->syncPermissions($permissions); 
            } else {
                $user->syncRoles(['customer']);
                $user->syncPermissions([]);
            }
        }

        $admin = User::where('email', 'admin@admin.com')->first();
        if ($admin) {
            $admin->syncRoles(['admin']);
            $admin->syncPermissions($permissions);
        }

        $this->info('Roles y permisos reasignados correctamente.');
    }
}