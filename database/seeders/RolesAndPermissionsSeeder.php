<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Lista de permisos
        $permissions = [
            "see-own-purchases",
            "see-all-reports",
            "see-all-products",
            "see-all-clients",
            "see-all-purchases",
            "edit-content",
            "edit-products",
            "manage-users",
            "manage-categories",
            "manage-admins",

            // Address related permissions
            "read-all-addresses",
            "see-own-addresses",
            "create-address",
            "update-address",
            "delete-address",

            // FAQ related permissions
            "manage-faq",
            "store-faq",
            "update-faq",
            "delete-faq",

            // List permissions names
            "see-all-permissions",
        ];

        // Crear permisos si no existen
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Roles y sus permisos
        $roles = [
            'superadmin' => [
                "see-own-purchases",
                "see-all-reports",
                "see-all-products",
                "see-all-clients",
                "see-all-purchases",
                "edit-content",
                "edit-products",
                "manage-users",
                "manage-categories",
                "manage-admins",

                // Address related permissions
                "read-all-addresses",
                "see-own-addresses",
                "create-address",
                "update-address",
                "delete-address",

                // FAQ related permissions
                "manage-faq",
                "store-faq",
                "update-faq",
                "delete-faq",

                // List permissions names
                "see-all-permissions",
            ],
            'admin' => [
                "see-own-purchases",
                "see-all-reports",
                "see-all-products",
                "see-all-clients",
                "see-all-purchases",
                "edit-content",
                "edit-products",
                "manage-users",
                "manage-categories",

                // Address related permissions
                "read-all-addresses",
                "see-own-addresses",
                "create-address",
                "update-address",
                "delete-address",

                // FAQ related permissions
                "manage-faq",
                "store-faq",
                "update-faq",
                "delete-faq",

                // List permissions names
                "see-all-permissions",
            ],
            'supervisor' => [
                "see-own-purchases",
                "see-all-reports",
                "see-all-products",
                "see-all-clients",
                "see-all-purchases"
            ],
            'editor' => [
                "see-own-purchases",
                "see-all-products",
                "edit-content"
            ],
            'cliente' => [
                "see-own-purchases",
                "see-own-addresses",
                "create-address",
                "update-address",
                "delete-address",
            ],
        ];

        // Crear roles y asignar permisos
        foreach ($roles as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($perms);
        }

        // Asignar roles a usuarios de ejemplo (ajusta los IDs según tus usuarios)
        $superadmin = User::find(1);
        if ($superadmin) {
            $superadmin->assignRole('superadmin');
            $superadmin->givePermissionTo([
                "read-all-addresses",
                "create-address",
            ]);
        }

        $admin = User::find(2);
        if ($admin) {
            $admin->assignRole('admin');
        }

        $supervisor = User::find(3);
        if ($supervisor) {
            $supervisor->assignRole('supervisor');
            $supervisor->givePermissionTo([
                "read-all-addresses",

            ]);
        }

        $editor = User::find(4);
        if ($editor) {
            $editor->assignRole('editor');
        }

        $cliente = User::find(5);
        if ($cliente) {
            $cliente->assignRole('cliente');
            $cliente->givePermissionTo([
                "create-address",
                "update-address",
                "delete-address",
                "see-own-addresses",
                "see-own-purchases",
                "see-all-products",
            ]);
        }
    }
}
