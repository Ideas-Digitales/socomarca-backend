<?php

use App\Models\Branch;
use App\Models\User;

describe('Branches tests', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('Index endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            $route = route('branches.index');

            $this->getJson($route)->assertStatus(401);
        });

        it('returns 403 when authenticated without permission', function () {
            $route = route('branches.index');

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertForbidden();
        });

        it('returns empty list when user has permission but no branches', function () {
            $this->user->givePermissionTo('read-own-branches');
            $route = route('branches.index');

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertOk()
                ->assertJsonCount(0, 'data');
        });

        it('returns own branches when user has permission', function () {
            $this->user->givePermissionTo('read-own-branches');
            $branches = Branch::factory()->count(2)->create(['user_id' => $this->user->id]);
            $route = route('branches.index');

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson($route);

            $response
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonFragment([
                    'name' => $branches[0]->name,
                    'code' => $branches[0]->code,
                ])
                ->assertJsonFragment([
                    'name' => $branches[1]->name,
                    'code' => $branches[1]->code,
                ]);
        });

        it('does not return other users branches', function () {
            $this->user->givePermissionTo('read-own-branches');
            $otherUser = User::factory()->create();
            $otherBranch = Branch::factory()->create(['user_id' => $otherUser->id]);
            $route = route('branches.index');

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertOk()
                ->assertJsonMissingExact([
                    'name' => $otherBranch->name,
                    'code' => $otherBranch->code,
                ]);
        });

        it('respects pagination when per_page parameter is given', function () {
            $this->user->givePermissionTo('read-own-branches');
            Branch::factory()->count(15)->create(['user_id' => $this->user->id]);
            $route = route('branches.index', ['per_page' => 5]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson($route);

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
    });

    describe('Show endpoint', function () {
        it('returns 401 when unauthenticated', function () {
            $route = route('branches.show', ['branch' => 1]);

            $this->getJson($route)->assertStatus(401);
        });

        it('returns 403 when authenticated without permission', function () {
            $branch = Branch::factory()->create(['user_id' => $this->user->id]);
            $route = route('branches.show', ['branch' => $branch->id]);

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertForbidden();
        });

        it('returns 404 when branch does not exist', function () {
            $this->user->givePermissionTo('read-own-branches');
            $route = route('branches.show', ['branch' => 99999]);

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertNotFound();
        });

        it('returns 404 when requesting another users branch', function () {
            $this->user->givePermissionTo('read-own-branches');
            $otherUser = User::factory()->create();
            $otherBranch = Branch::factory()->create(['user_id' => $otherUser->id]);
            $route = route('branches.show', ['branch' => $otherBranch->id]);

            $this->actingAs($this->user, 'sanctum')
                ->getJson($route)
                ->assertNotFound();
        });

        it('returns branch data when user has permission and owns it', function () {
            $this->user->givePermissionTo('read-own-branches');
            $branch = Branch::factory()->create(['user_id' => $this->user->id]);
            $route = route('branches.show', ['branch' => $branch->id]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson($route);

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
    });
});
