<?php

use App\Models\Address;
use App\Models\Order;
use App\Models\User;

function runDeleteRandomProviderUsersMigration(): void
{
    $migration = require database_path('migrations/2026_10_13_120000_delete_random_provider_users.php');
    $migration->up();
}

it('deletes the provider users with their tokens and addresses', function () {
    $provider = User::factory()->create(['random_user_type' => 'P', 'email' => 'facturacionmipyme@sii.cl']);
    $provider->createToken('device', ['api-access']);
    Address::factory()->create(['user_id' => $provider->id]);

    runDeleteRandomProviderUsersMigration();

    expect(User::find($provider->id))->toBeNull();
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $provider->id]);
    $this->assertDatabaseMissing('addresses', ['user_id' => $provider->id]);
});

it('deletes the provider users with the customer role', function () {
    $provider = User::factory()->create(['random_user_type' => 'P']);
    $provider->assignRole('customer');

    runDeleteRandomProviderUsersMigration();

    expect(User::find($provider->id))->toBeNull();
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $provider->id]);
});

it('keeps the customers and the users not created from Random', function () {
    $customer = User::factory()->create(['random_user_type' => 'C']);
    $both = User::factory()->create(['random_user_type' => 'A']);
    $local = User::factory()->create(['random_user_type' => null]);

    runDeleteRandomProviderUsersMigration();

    expect(User::whereIn('id', [$customer->id, $both->id, $local->id])->count())->toBe(3);
});

it('keeps the provider users with a role other than customer', function () {
    $admin = User::factory()->create(['random_user_type' => 'P']);
    $admin->assignRole('admin');

    runDeleteRandomProviderUsersMigration();

    expect(User::find($admin->id))->not->toBeNull();
});

it('keeps the provider users with orders', function (string $column) {
    $provider = User::factory()->create(['random_user_type' => 'P']);
    $order = Order::factory()->create([$column => $provider->id]);

    runDeleteRandomProviderUsersMigration();

    expect(User::find($provider->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull();
})->with(['user_id', 'customer_id']);
