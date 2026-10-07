<?php

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

function dropBranchesMigration(): object
{
    return require database_path('migrations/2026_10_07_120000_drop_branches_table.php');
}

it('drops the branches table and orders.branch_id', function () {
    expect(Schema::hasTable('branches'))->toBeFalse()
        ->and(Schema::hasColumn('orders', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('orders', 'customer_id'))->toBeTrue();
});

it('deletes the branches permissions', function () {
    $migration = dropBranchesMigration();
    $migration->down();
    foreach (['read-all-branches', 'read-own-branches'] as $name) {
        Permission::create(['name' => $name, 'guard_name' => 'web'])->assignRole('customer');
    }

    $migration->up();

    expect(Permission::whereIn('name', ['read-all-branches', 'read-own-branches'])->exists())->toBeFalse();
});

it('restores the branches schema on rollback', function () {
    dropBranchesMigration()->down();

    expect(Schema::hasTable('branches'))->toBeTrue()
        ->and(Schema::hasColumn('branches', 'branch_type'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'branch_id'))->toBeTrue();
});
