<?php

use App\Models\Subcategory;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\SubcategoryScenario;
use function Pest\Laravel\getJson;

describe('Subcategory API', function () {
    describe('Authorization', function () {
        it('should require authentication for index', function () {
            $response = getJson(route('subcategories.index'));
            $response->assertStatus(401);
        });

        it('should require permission for index', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            $user->syncPermissions([])->save();
            $user->refresh();
            Sanctum::actingAs($user, ['api-access']);
            $response = getJson(route('subcategories.index'));
            $response->assertStatus(403);
        });

        it('should allow access to index with permission', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            Sanctum::actingAs($user, ['api-access']);
            getJson(route('subcategories.index'))->assertStatus(200);
        });

        it('should require authentication for show', function () {
            $scenario = SubcategoryScenario::make();
            $response = getJson(route('subcategories.show', ['subcategory' => $scenario->subcategory->id]));
            $response->assertStatus(401);
        });

        it('should require permission for show', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            $user->syncPermissions([])->save();
            $user->refresh();
            Sanctum::actingAs($user, ['api-access']);
            $response = getJson(route('subcategories.show', ['subcategory' => $scenario->subcategory->id]));
            $response->assertStatus(403);
        });

        it('should allow access to show with permission', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            Sanctum::actingAs($user, ['api-access']);
            $response = getJson(route('subcategories.show', ['subcategory' => $scenario->subcategory->id]));
            $response->assertStatus(200);
        });
    });

    describe('Functional', function () {
        it('should return 401 if token is missing', function () {
            $response = getJson(route('subcategories.index'))->assertStatus(401);
        });

        it('should return 200 and correct structure for index', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            Sanctum::actingAs($user, ['api-access']);
            getJson(route('subcategories.index'))
                ->assertStatus(200)
                ->assertJsonStructure($scenario->listJsonStructure);
        });

        it('should return 404 for non-existent subcategory', function () {
            $scenario = SubcategoryScenario::make();
            $user = $scenario->user;
            Sanctum::actingAs($user, ['api-access']);
            $id = $scenario->subcategory->id;
            Subcategory::truncate();
            $response = getJson(route('subcategories.show', ['subcategory' => $id]));
            $response->assertStatus(404);
        });
    });
});
