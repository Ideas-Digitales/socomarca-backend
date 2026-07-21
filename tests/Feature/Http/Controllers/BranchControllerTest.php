<?php

use App\Models\Branch;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

describe('Branches tests', function () {
    describe('Index endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            $route = route('branches.index');

            getJson($route)->assertStatus(401);
        });

        it('returns 403 when authenticated without permission', function () {
            $user = User::factory()->create();
            $route = route('branches.index');
            Sanctum::actingAs($user, ['api-access']);
            getJson($route)->assertForbidden();
        });

        it('returns empty list when user has permission but no branches', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $route = route('branches.index');

            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertOk()
                ->assertJsonCount(0, 'data');
        });

        it('returns own branches when user has permission', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $branches = Branch::factory()->count(2)->create(['user_id' => $user->id]);
            $route = route('branches.index');

            Sanctum::actingAs($user, ['api-access']);
            $response = getJson($route);

            $response
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonFragment([
                    'id' => $branches[0]->id,
                    'name' => $branches[0]->name,
                    'code' => $branches[0]->code,
                ])
                ->assertJsonFragment([
                    'id' => $branches[1]->id,
                    'name' => $branches[1]->name,
                    'code' => $branches[1]->code,
                ]);
        });

        it('does not return other users branches', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $otherUser = User::factory()->create();
            $otherBranch = Branch::factory()->create(['user_id' => $otherUser->id]);
            $route = route('branches.index');

            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertOk()
                ->assertJsonMissingExact([
                    'name' => $otherBranch->name,
                    'code' => $otherBranch->code,
                ]);
        });

        it('respects pagination when per_page parameter is given', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            Branch::factory()->count(15)->create(['user_id' => $user->id]);
            $route = route('branches.index', ['per_page' => 5]);

            Sanctum::actingAs($user, ['api-access']);
            $response = getJson($route);
            $response
                ->assertOk()
                ->assertJsonCount(5, 'data')
                ->assertJsonStructure([
                    'data' => [
                        ['name', 'code', 'email', 'commercial_email', 'phone', 'rut', 'business_name'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        it('does not return primary branches in index', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $primaryBranch = Branch::factory()->create([
                'user_id'     => $user->id,
                'branch_type' => 'P',
            ]);
            $secondaryBranch = Branch::factory()->create(['user_id' => $user->id]);
            $route = route('branches.index');

            Sanctum::actingAs($user, ['api-access']);
            $response = getJson($route);

            $response
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonFragment([
                    'name' => $secondaryBranch->name,
                    'code' => $secondaryBranch->code,
                ])
                ->assertJsonMissingExact([
                    'name' => $primaryBranch->name,
                    'code' => $primaryBranch->code,
                ]);
        });
    });

    describe('Show endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            $route = route('branches.show', ['branch' => 1]);

            getJson($route)->assertStatus(401);
        });

        it('returns 403 when authenticated without permission', function () {
            $user = User::factory()->create();
            $branch = Branch::factory()->create(['user_id' => $user->id]);
            $route = route('branches.show', ['branch' => $branch->id]);

            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertForbidden();
        });

        it('returns 404 when branch does not exist', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $route = route('branches.show', ['branch' => 99999]);

            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertNotFound();
        });

        it('returns 404 when requesting another users branch', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $otherUser = User::factory()->create();
            $otherBranch = Branch::factory()->create(['user_id' => $otherUser->id]);
            $route = route('branches.show', ['branch' => $otherBranch->id]);

            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertNotFound();
        });

        it('returns branch data when user has permission and owns it', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $branch = Branch::factory()->create(['user_id' => $user->id]);
            $route = route('branches.show', ['branch' => $branch->id]);

            Sanctum::actingAs($user, ['api-access']);
            $response = getJson($route);

            $response
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'name',
                        'code',
                        'email',
                        'commercial_email',
                        'phone',
                        'rut',
                        'business_name',
                    ],
                ])
                ->assertJsonFragment([
                    'name' => $branch->name,
                    'code' => $branch->code,
                ]);
        });
        it('returns 404 when requesting a primary branch', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-own-branches');
            $primaryBranch = Branch::factory()->create([
                'user_id'     => $user->id,
                'branch_type' => 'P',
            ]);
            $route = route('branches.show', ['branch' => $primaryBranch->id]);

            Sanctum::actingAs($user, ['api-access']);
            getJson($route)
                ->assertNotFound();
        });
    });
});
