<?php

use App\Models\Brand;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

/**
 * Create a user allowed to read brands and to see the test price list.
 */
function brandUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('read-all-brands');
    $user->update(['prices_lists' => [getPriceListCode()]]);

    return $user;
}

/**
 * Create a brand with one product priced on the test price list.
 *
 * @param array $priceAttributes Overrides for the price row, e.g. stock or price
 */
function brandWithProduct(string $name, array $priceAttributes = []): Brand
{
    $brand = Brand::factory()->create(['name' => $name]);

    $product = Product::create([
        'name' => "Producto {$name}",
        'sku' => 'SKU-' . uniqid(),
        'brand_id' => $brand->id,
        'status' => true,
    ]);

    Price::create(array_merge([
        'product_id' => $product->id,
        'price_list_id' => getPriceListCode(),
        'unit' => 'un',
        'price' => 5000,
        'stock' => 50,
        'is_active' => true,
        'valid_from' => now()->subDays(10),
    ], $priceAttributes));

    return $brand;
}

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
        Sanctum::actingAs(brandUser(), ['api-access']);
        $response = getJson(route('brands.index'));
        $response->assertStatus(200);
    });
});

describe('Brand ordering', function () {
    it('should return brands sorted alphabetically by name', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        // Se insertan al revés del alfabeto a propósito: sin el orderBy('name') del
        // controlador, Postgres las devuelve en orden de inserción y el test falla.
        brandWithProduct('ZUKO');
        brandWithProduct('ABOLENGO');
        brandWithProduct('MOLICO');

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))
            ->toBe(['ABOLENGO', 'MOLICO', 'ZUKO']);
    });

    it('should not push accented names to the end of the list', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        // Comparadas por code point, Á/Ñ/Ó quedan después de la Z y una marca con
        // tilde caería al final. Este caso fija que el orden respeta el español.
        brandWithProduct('ZUKO');
        brandWithProduct('HERNÁNDEZ');
        brandWithProduct('ABOLENGO');

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))
            ->toBe(['ABOLENGO', 'HERNÁNDEZ', 'ZUKO']);
    });
});

describe('Brand availability', function () {
    it('should hide brands without products', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        brandWithProduct('CON PRODUCTO');
        Brand::factory()->create(['name' => 'SIN PRODUCTO']);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))->toBe(['CON PRODUCTO']);
    });

    it('should hide brands whose products are out of stock', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        brandWithProduct('CON STOCK');
        brandWithProduct('SIN STOCK', ['stock' => 0]);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))->toBe(['CON STOCK']);
    });

    it('should hide brands whose products have a zero price', function () {
        config(['random.show_product_zero_price' => false]);
        Sanctum::actingAs(brandUser(), ['api-access']);

        brandWithProduct('CON PRECIO');
        brandWithProduct('PRECIO CERO', ['price' => 0]);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))->toBe(['CON PRECIO']);
    });

    it('should hide brands whose products only have inactive prices', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        brandWithProduct('PRECIO ACTIVO');
        brandWithProduct('PRECIO INACTIVO', ['is_active' => false]);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))->toBe(['PRECIO ACTIVO']);
    });

    it('should hide brands priced only on a list the user cannot read', function () {
        Sanctum::actingAs(brandUser(), ['api-access']);

        brandWithProduct('MI LISTA');
        brandWithProduct('OTRA LISTA', ['price_list_id' => getPriceListCode() . '-OTRA']);

        $response = getJson(route('brands.index'));

        $response->assertStatus(200);
        expect(array_column($response->json(), 'name'))->toBe(['MI LISTA']);
    });
});
