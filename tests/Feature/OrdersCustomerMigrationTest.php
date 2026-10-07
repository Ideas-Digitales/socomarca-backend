<?php

use App\Enums\BranchType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Restore the branches table and orders.branch_id, dropped by a later migration, so that
 * the test data can be inserted as it existed before the deploy.
 */
beforeEach(function () {
    $dropBranches = require database_path('migrations/2026_10_07_120000_drop_branches_table.php');
    $dropBranches->down();
});

/**
 * Run the migration over the current data, as it happens on deploy: RefreshDatabase already
 * migrated, so the column is dropped first and the migration is run again.
 */
function runOrdersCustomerMigration(): void
{
    $migration = require database_path('migrations/2026_10_06_120000_add_customer_id_to_orders_table.php');
    $migration->down();
    $migration->up();
}

function insertBranch(User $owner, array $attributes = []): int
{
    return DB::table('branches')->insertGetId(array_merge([
        'name' => 'Sucursal',
        'code' => 'CM',
        'user_code' => '77528378',
        'email' => 'dte@cliente.cl',
        'commercial_email' => 'compras@cliente.cl',
        'phone' => null,
        'rut' => '77528378-K',
        'business_name' => 'Cliente SPA',
        'user_id' => $owner->id,
        'branch_type' => BranchType::SECONDARY,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function orderWithBranch(User $user, ?int $branchId): Order
{
    $order = Order::factory()->create(['user_id' => $user->id]);
    DB::table('orders')->where('id', $order->id)->update(['branch_id' => $branchId]);

    return $order;
}

function createBranchesOwner(): User
{
    $owner = User::factory()->create([
        'user_code' => '77528378',
        'branch_code' => 'CM',
        'billing_email' => null,
    ]);
    $owner->assignRole('customer');

    return $owner;
}

it('marks the owner of a primary branch and assigns it its orders', function () {
    $owner = createBranchesOwner();
    $primary = insertBranch($owner, [
        'branch_type' => BranchType::PRIMARY,
        'email' => ' DTE@Cliente.cl ',
    ]);
    $order = orderWithBranch($owner, $primary);

    runOrdersCustomerMigration();

    $owner = $owner->fresh();
    expect($owner->branch_type)->toBe(BranchType::PRIMARY)
        ->and($owner->billing_email)->toBe('dte@cliente.cl')
        ->and($order->fresh()->customer_id)->toBe($owner->id);
});

it('creates a customer user for every secondary branch and assigns it its orders', function () {
    $owner = createBranchesOwner();
    $secondary = insertBranch($owner, [
        'name' => 'Sucursal Los Leones',
        'code' => ' LO ',
        'commercial_email' => 'Leones@Cliente.cl',
        'email' => 'dte@cliente.cl',
        'phone' => '221234567',
    ]);
    $order = orderWithBranch($owner, $secondary);

    runOrdersCustomerMigration();

    $branchUser = User::where('branch_code', 'LO')->sole();
    expect($branchUser->only([
        'name', 'email', 'billing_email', 'phone', 'rut', 'user_code', 'business_name',
        'branch_type', 'random_entity_id', 'password',
    ]))->toBe([
        'name' => 'Sucursal Los Leones',
        'email' => 'leones@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
        'phone' => '221234567',
        'rut' => '77528378-K',
        'user_code' => '77528378',
        'business_name' => 'Cliente SPA',
        'branch_type' => BranchType::SECONDARY,
        'random_entity_id' => null,
        'password' => null,
    ])
        ->and($branchUser->is_active)->toBeTrue()
        ->and($branchUser->hasRole('customer'))->toBeTrue();

    $order = $order->fresh();
    expect($order->customer_id)->toBe($branchUser->id)
        ->and($order->user_id)->toBe($owner->id);
});

it('reuses the user that already has the KOEN and SUEN of a secondary branch', function () {
    $owner = createBranchesOwner();
    $existing = User::factory()->create(['user_code' => '77528378', 'branch_code' => 'LO']);
    $secondary = insertBranch($owner, ['code' => 'LO']);
    $order = orderWithBranch($owner, $secondary);
    $usersBefore = User::count();

    runOrdersCustomerMigration();

    expect(User::count())->toBe($usersBefore)
        ->and($order->fresh()->customer_id)->toBe($existing->id)
        ->and($existing->fresh()->hasRole('customer'))->toBeTrue();
});

it('assigns the orders without branch to the user who placed them', function () {
    $owner = createBranchesOwner();
    $order = orderWithBranch($owner, null);

    runOrdersCustomerMigration();

    expect($order->fresh()->customer_id)->toBe($owner->id);
});

it('requires the customer of every order', function () {
    $owner = createBranchesOwner();
    runOrdersCustomerMigration();

    expect(fn () => DB::table('orders')->insert([
        'user_id' => $owner->id,
        'customer_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'customer_id');
});
