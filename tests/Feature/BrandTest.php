<?php

use App\Models\Brand;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

describe('Brand authorization', function () {
    it('should require authentication for index', function () {
        $response = getJson(route('brands.index'));
        $response->assertStatus(401);
    });

    it('should require permission for index', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);
        $response = getJson(route('brands.index'));
        $response->assertStatus(403);
    });

    it('should allow access to index with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-brands');
        Sanctum::actingAs($user, ['api-access']);
        $response = getJson(route('brands.index'));
        $response->assertStatus(200);
    });
});

describe('Brand ordering', function () {
    it('should return brands sorted alphabetically by name', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-brands');
        Sanctum::actingAs($user, ['api-access']);

        // Se insertan al revés del alfabeto a propósito: sin el orderBy('name') del
        // controlador, Postgres las devuelve en orden de inserción y el test falla.
        Brand::factory()->create(['name' => 'ZUKO']);
        Brand::factory()->create(['name' => 'ABOLENGO']);
        Brand::factory()->create(['name' => 'MOLICO']);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))
            ->toBe(['ABOLENGO', 'MOLICO', 'ZUKO']);
    });

    it('should not push accented names to the end of the list', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-brands');
        Sanctum::actingAs($user, ['api-access']);

        // Comparadas por code point, Á/Ñ/Ó quedan después de la Z y una marca con
        // tilde caería al final. Este caso fija que el orden respeta el español.
        Brand::factory()->create(['name' => 'ZUKO']);
        Brand::factory()->create(['name' => 'HERNÁNDEZ']);
        Brand::factory()->create(['name' => 'ABOLENGO']);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))
            ->toBe(['ABOLENGO', 'HERNÁNDEZ', 'ZUKO']);
    });
});
