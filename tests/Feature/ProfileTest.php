<?php

use App\Enums\BranchType;
use App\Models\Address;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\ProfileScenario;

use function Pest\Laravel\getJson;

it('allows a user to view their own profile information', function () {
    $scenario = ProfileScenario::make();

    $user = User::factory()
        ->has(
            Address::factory([
                'type' => 'shipping',
                'is_default' => 1,
            ])->count(1)
        )->has(
            Address::factory([
                'type' => 'billing',
                'is_default' => 1,
            ])->count(1)
        )->has(
            Address::factory([
                'type' => 'shipping',
                'is_default' => 0,
            ])->count(2)
        )->create();

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertStatus(200)
        ->assertJsonStructure($scenario->profileStructure)
        ->assertJsonFragment(['rut' => $user->rut])
        ->assertJson(fn (AssertableJson $json) =>
            $json->where('rut', $user->rut)
                ->where('name', $user->name)
                ->where('billing_address.id', $user->billing_address->id)
                ->where('default_shipping_address.id', $user->default_shipping_address->id)
                ->etc()
        );
});

it('allows a user to view their own profile even without associated addresses', function () {
    $user = User::factory()->create();
    Address::where('user_id', $user->id)->delete();

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertStatus(200)
        ->assertJson(fn (AssertableJson $json) =>
            $json->where('rut', $user->rut)
                ->where('name', $user->name)
                ->where('billing_address', null)
                ->where('default_shipping_address', null)
                ->etc()
        );
});

it('returns the branch type and whether the user can order for other branches', function (array $secondaries, bool $expected) {
    $user = createSyncedCustomer(['branch_code' => 'CM']);

    foreach ($secondaries as $index => $attributes) {
        createSyncedCustomer(array_merge([
            'branch_code' => "S{$index}",
            'branch_type' => BranchType::SECONDARY,
        ], $attributes));
    }

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('branch_type', BranchType::PRIMARY)
        ->assertJsonPath('can_order_for_branches', $expected);
})->with([
    'with an active secondary branch' => [[[]], true],
    'without secondary branches' => [[], false],
    'with an inactive secondary branch only' => [[['is_active' => false]], false],
]);

it('does not let a secondary branch order for other branches', function () {
    createSyncedCustomer(['branch_code' => 'CM']);
    $secondary = createSyncedCustomer([
        'branch_code' => 'LO',
        'branch_type' => BranchType::SECONDARY,
    ]);
    createSyncedCustomer([
        'branch_code' => 'LA',
        'branch_type' => BranchType::SECONDARY,
    ]);

    Sanctum::actingAs($secondary, ['api-access']);

    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('branch_type', BranchType::SECONDARY)
        ->assertJsonPath('can_order_for_branches', false);
});

it('returns a null branch type for internal users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Sanctum::actingAs($admin, ['api-access']);

    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('branch_type', null)
        ->assertJsonPath('can_order_for_branches', false);
});

it('flags the synced addresses of the profile', function () {
    $user = User::factory()->create();
    Address::where('user_id', $user->id)->delete();
    $synced = Address::factory()->create([
        'user_id' => $user->id,
        'type' => 'shipping',
        'is_default' => true,
        'is_synced' => true,
    ]);

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('default_shipping_address.id', $synced->id)
        ->assertJsonPath('default_shipping_address.is_synced', true);
});
