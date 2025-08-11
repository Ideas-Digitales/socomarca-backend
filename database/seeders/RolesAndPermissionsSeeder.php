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
            "see-all-reports",
            "see-all-products",
            "see-all-clients",
            "edit-content",
            "edit-products",
            "manage-users",
            "manage-categories",
            "manage-admins",

            // Address related permissions
            "see-all-addresses",
            "see-own-addresses",
            "store-address",
            "update-address",
            "delete-address",

            // FAQ related permissions
            "manage-faq",
            "store-faq",
            "update-faq",
            "delete-faq",

            // Cart related permissions
            "read-own-cart",
            "delete-cart",
            "create-cart-items",
            "delete-cart-items",

            // Order related permissions
            "read-own-orders",
            "create-orders",
            "update-orders",

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
                "see-all-reports",
                "see-all-products",
                "see-all-clients",
                "edit-content",
                "edit-products",
                "manage-users",
                "manage-categories",
                "manage-admins",

                // Address related permissions
                "see-all-addresses",
                "see-own-addresses",
                "store-address",
                "update-address",
                "delete-address",

                // FAQ related permissions
                "manage-faq",
                "store-faq",
                "update-faq",
                "delete-faq",

                // Cart related permissions
                "read-own-cart",
                "delete-cart",
                "create-cart-items",
                "delete-cart-items",

                // Order related permissions
                "read-own-orders",
                "create-orders",
                "update-orders",

                // List permissions names
                "see-all-permissions",
            ],
            'admin' => [
                "see-all-reports",
                "see-all-products",
                "see-all-clients",
                "edit-content",
                "edit-products",
                "manage-users",
                "manage-categories",

                // Address related permissions
                "see-all-addresses",
                "see-own-addresses",
                "store-address",
                "update-address",
                "delete-address",

                // FAQ related permissions
                "manage-faq",
                "store-faq",
                "update-faq",
                "delete-faq",

                // Cart related permissions
                "read-own-cart",
                "delete-cart",
                "create-cart-items",
                "delete-cart-items",

                // Order related permissions
                "read-own-orders",
                "create-orders",
                "update-orders",

                // List permissions names
                "see-all-permissions",
            ],
            'supervisor' => [
                "see-all-reports",
                "see-all-products",
                "see-all-clients"
            ],
            'editor' => [
                "see-all-products",
                "edit-content"
            ],
            'customer' => [
                "see-own-addresses",
                "store-address",
                "update-address",
                "delete-address",

                // Cart related permissions
                "read-own-cart",
                "delete-cart", 
                "create-cart-items",
                "delete-cart-items",

                // Order related permissions
                "read-own-orders",
                "create-orders",
                "update-orders",
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
                "see-all-addresses",
                "store-address",
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
                "see-all-addresses",

            ]);
        }

        $editor = User::find(4);
        if ($editor) {
            $editor->assignRole('editor');
        }

        $customer = User::find(5);
        if ($customer) {
            $customer->assignRole('customer');
            $customer->givePermissionTo([
                "store-address",
                "update-address",
                "delete-address",
                "see-own-addresses",
                "see-all-products",
                "read-own-cart",
                "delete-cart",
                "create-cart-items", 
                "delete-cart-items",
                "read-own-orders",
                "create-orders",
                "update-orders",
            ]);
        }
    }
}
