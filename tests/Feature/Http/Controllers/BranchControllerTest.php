<?php

use App\Enums\BranchType;
use App\Models\Address;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

function createSecondaryBranch(array $attributes = []): User
{
    return createSyncedCustomer(array_merge([
        'branch_code' => 'LO',
        'branch_type' => BranchType::SECONDARY,
    ], $attributes));
}

describe('Branches tests', function () {
    describe('Index endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            getJson(route('branches.index'))->assertUnauthorized();
        });

        it('returns the active secondary branches of the primary branch entity', function () {
            $primary = createSyncedCustomer(['branch_code' => 'CM']);
            $secondary = createSecondaryBranch([
                'name' => 'Sucursal Los Leones',
                'billing_email' => 'dte@cliente.cl',
                'phone' => '221234567',
            ]);
            createSecondaryBranch(['branch_code' => 'IN', 'is_active' => false]);
            createSecondaryBranch(['branch_code' => 'OT', 'user_code' => '99999999']);
            createSyncedCustomer(['branch_code' => 'P2']);

            Sanctum::actingAs($primary, ['api-access']);
            getJson(route('branches.index'))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0', [
                    'id' => $secondary->id,
                    'name' => 'Sucursal Los Leones',
                    'email' => $secondary->email,
                    'billing_email' => 'dte@cliente.cl',
                    'phone' => '221234567',
                    'rut' => $secondary->rut,
                    'business_name' => $secondary->business_name,
                    'user_code' => '77528378',
                    'branch_code' => 'LO',
                    'branch_type' => BranchType::SECONDARY,
                    'addresses' => [],
                ]);
        });

        it('includes the addresses of each branch', function () {
            $primary = createSyncedCustomer(['branch_code' => 'CM']);
            $secondary = createSecondaryBranch();
            $address = Address::factory()->create(['user_id' => $secondary->id]);
            Address::factory()->create(['user_id' => $primary->id]);

            Sanctum::actingAs($primary, ['api-access']);
            getJson(route('branches.index'))
                ->assertOk()
                ->assertJsonCount(1, 'data.0.addresses')
                ->assertJsonPath('data.0.addresses.0.id', $address->id)
                ->assertJsonPath('data.0.addresses.0.municipality_name', $address->municipality->name)
                ->assertJsonPath('data.0.addresses.0.region_name', $address->municipality->region->name);
        });

        it('returns an empty list to users that are not a primary branch', function (Closure $makeUser) {
            createSyncedCustomer(['branch_code' => 'CM']);
            createSecondaryBranch();
            createSecondaryBranch(['branch_code' => 'S2']);

            Sanctum::actingAs($makeUser(), ['api-access']);
            getJson(route('branches.index'))
                ->assertOk()
                ->assertJsonCount(0, 'data');
        })->with([
            'secondary branch' => [fn () => createSecondaryBranch(['branch_code' => 'S3'])],
            'internal user' => [fn () => User::factory()->create()],
        ]);

        it('respects pagination when per_page parameter is given', function () {
            $primary = createSyncedCustomer(['branch_code' => 'CM']);
            foreach (range(1, 7) as $index) {
                createSecondaryBranch(['branch_code' => "S{$index}"]);
            }

            Sanctum::actingAs($primary, ['api-access']);
            getJson(route('branches.index', ['per_page' => 5]))
                ->assertOk()
                ->assertJsonCount(5, 'data')
                ->assertJsonPath('meta.total', 7)
                ->assertJsonStructure(['data', 'links', 'meta']);
        });
    });

    describe('Show endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            $secondary = createSecondaryBranch();

            getJson(route('branches.show', ['branch' => $secondary->id]))->assertUnauthorized();
        });

        it('returns a secondary branch of the primary branch entity with its addresses', function () {
            $primary = createSyncedCustomer(['branch_code' => 'CM']);
            $secondary = createSecondaryBranch();
            $address = Address::factory()->create(['user_id' => $secondary->id]);

            Sanctum::actingAs($primary, ['api-access']);
            getJson(route('branches.show', ['branch' => $secondary->id]))
                ->assertOk()
                ->assertJsonPath('data.id', $secondary->id)
                ->assertJsonPath('data.branch_code', 'LO')
                ->assertJsonPath('data.branch_type', BranchType::SECONDARY)
                ->assertJsonCount(1, 'data.addresses')
                ->assertJsonPath('data.addresses.0.id', $address->id);
        });

        it('returns 404 when the branch does not exist', function () {
            $primary = createSyncedCustomer(['branch_code' => 'CM']);

            Sanctum::actingAs($primary, ['api-access']);
            getJson(route('branches.show', ['branch' => 99999]))->assertNotFound();
        });

        it('returns 404 for users the authenticated user cannot order for', function (Closure $makeUser, Closure $makeTarget) {
            createSyncedCustomer(['branch_code' => 'CM']);
            $user = $makeUser();
            $target = $makeTarget();

            Sanctum::actingAs($user, ['api-access']);
            getJson(route('branches.show', ['branch' => $target->id]))->assertNotFound();
        })->with([
            'inactive secondary branch' => [
                fn () => User::where('branch_code', 'CM')->sole(),
                fn () => createSecondaryBranch(['is_active' => false]),
            ],
            'secondary branch of another entity' => [
                fn () => User::where('branch_code', 'CM')->sole(),
                fn () => createSecondaryBranch(['user_code' => '99999999']),
            ],
            'another primary branch' => [
                fn () => User::where('branch_code', 'CM')->sole(),
                fn () => createSyncedCustomer(['branch_code' => 'P2']),
            ],
            'sibling, from a secondary branch' => [
                fn () => createSecondaryBranch(),
                fn () => createSecondaryBranch(['branch_code' => 'S2']),
            ],
            'primary branch, from its secondary branch' => [
                fn () => createSecondaryBranch(),
                fn () => User::where('branch_code', 'CM')->sole(),
            ],
            'secondary branch, from an internal user' => [
                fn () => User::factory()->create(),
                fn () => createSecondaryBranch(),
            ],
        ]);
    });
});
