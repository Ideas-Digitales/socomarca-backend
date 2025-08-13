<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subcategory;

beforeEach(function () {
    // Estructura de la respuesta para la búsqueda, incluyendo los filtros devueltos
    $this->searchResponseStructure = [
        'data' => [
            '*' => [
                'id',
                'name',
                'unit',
                'price',
                'stock',
                'image',
                'sku',
                'is_favorite',
                'category' => ['id', 'name'],
                'subcategory' => ['id', 'name'],
                'brand' => ['id', 'name'],
            ],
        ],
        'meta', // Estructura de paginación
        'filters' => [ // Filtros devueltos
            'min_price',
            'max_price',
        ]
    ];
});

describe('Product list endpoint', function () {
    it('should return 401 without authentication', function () {
        $this
            ->getJson(route('products.index'))
            ->assertStatus(401);
    });

    it('should return 403 when not having the \'read-all-products\' permission', function () {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index'))
            ->assertForbidden();
    });

    it('should response a product list filtered by price range', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();
        $minSearch = 60000;
        $maxSearch = 90000;

        // Crea productos, uno de ellos garantizado dentro del rango
        Product::factory()->has(Price::factory(['price' => 75000, 'is_active' => true]))->create();
        Product::factory()->has(Price::factory(['price' => 40000, 'is_active' => true]))->create(); // Fuera de rango

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => [
                        'min' => $minSearch,
                        'max' => $maxSearch,
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->not->toBeEmpty();
        foreach ($response->json('data') as $product) {
            expect($product['price'])->toBeGreaterThanOrEqual($minSearch);
            expect($product['price'])->toBeLessThanOrEqual($maxSearch);
        }
    });

    it('should apply optional filters for category, subcategory, brand, and name', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $brand = Brand::factory()->create();

        // Producto que coincide con todos los filtros
        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create([
                'name' => 'Producto Estrella',
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'brand_id' => $brand->id,
            ]);

        // Producto que no coincide
        Product::factory()->has(Price::factory(['price' => 5000, 'is_active' => true]))->create(['name' => 'Otro Producto']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000], // Rango obligatorio
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory->id,
                    'brand_id' => [$brand->id],
                    'name' => 'Estrella', // Búsqueda parcial por nombre
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        // Debería encontrar solo 1 producto
        expect($response->json('data'))->toHaveCount(1);
        $foundProduct = $response->json('data.0');
        expect($foundProduct['name'])->toBe('Producto Estrella');
        expect($foundProduct['category']['id'])->toBe($category->id);
        expect($foundProduct['subcategory']['id'])->toBe($subcategory->id);
        expect($foundProduct['brand']['id'])->toBe($brand->id);
    });

    it('should apply sorting by price, stock, category_name and id', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crea categorías
        $catA = \App\Models\Category::factory()->create(['name' => 'Alimentos']);
        $catB = \App\Models\Category::factory()->create(['name' => 'Bebidas']);

        // Crea productos con precios y stock distintos
        $p1 = Product::factory()->for($catA)->has(Price::factory(['price' => 1000, 'stock' => 5, 'is_active' => true, 'unit' => 'kg']))->create(['name' => 'Producto 1']);
        $p2 = Product::factory()->for($catB)->has(Price::factory(['price' => 2000, 'stock' => 10, 'is_active' => true, 'unit' => 'kg']))->create(['name' => 'Producto 2']);
        $p3 = Product::factory()->for($catA)->has(Price::factory(['price' => 1500, 'stock' => 7, 'is_active' => true, 'unit' => 'kg']))->create(['name' => 'Producto 3']);

        // Ordenar por price asc
        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index', ['sort' => 'price', 'sort_direction' => 'asc']))
            ->assertStatus(200);

        $prices = array_column($response->json('data'), 'price');
        expect($prices)->toBe([1000, 1500, 2000]);

        // Ordenar por stock desc
        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index', ['sort' => 'stock', 'sort_direction' => 'desc']))
            ->assertStatus(200);
        $stocks = array_column($response->json('data'), 'stock');
        expect($stocks)->toBe([10, 7, 5]);

        // Ordenar por category_name asc
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/products?sort=category_name&sort_direction=asc');
        $response->assertStatus(200);
        $categories = array_column($response->json('data'), 'category');
        $categoryNames = array_column($categories, 'name');
        expect($categoryNames)->toBe(['Alimentos', 'Alimentos', 'Bebidas']);

        // Ordenar por id desc
        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index', ['sort' => 'id', 'sort_direction' => 'desc']))
            ->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        rsort($ids);
        expect($response->json('data.0.id'))->toBe($ids[0]);
    });

    it('should apply is_favorite filter when provided', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        $favoriteList = FavoriteList::factory()->create(['user_id' => $user->id]);
        $favoriteProduct = Product::factory()
            ->has(Price::factory(['price' => 5000]))
            ->create();
        Favorite::factory()->create([
            'favorite_list_id' => $favoriteList->id,
            'product_id' => $favoriteProduct->id,
        ]);

        $nonFavoriteProduct = Product::factory()
            ->has(Price::factory(['price' => 6000]))
            ->create();

        $responseFavorite = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000], // Rango obligatorio
                    'is_favorite' => true,
                ]
            ]);

        $responseFavorite->assertStatus(200);
        // Debería encontrar solo el producto favorito
        expect($responseFavorite->json('data'))->toHaveCount(1);
        expect($responseFavorite->json('data.0.id'))->toBe($favoriteProduct->id);
        expect($responseFavorite->json('data.0.is_favorite'))->toBeTrue();

        $responseNonFavorite = $this->actingAs($user, 'sanctum')
            ->postJson('/api/products/search', [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000], // Rango obligatorio
                    'is_favorite' => false,
                ]
            ]);

        $responseNonFavorite->assertStatus(200);
        // Debería encontrar solo el producto que NO es favorito
        expect($responseNonFavorite->json('data'))->toHaveCount(1);
        expect($responseNonFavorite->json('data.0.id'))->toBe($nonFavoriteProduct->id);
        expect($responseNonFavorite->json('data.0.is_favorite'))->toBeFalse();
    });
});

describe('Product search endpoint', function () {
    it('should fail if price range is missing', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'name' => 'un producto cualquiera'
                ]
            ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrorFor('filters.price');
    });

    it('should fail validation if brand_id is not an array', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        $brand = Brand::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 0, 'max' => 20000],
                    'brand_id' => $brand->id,
                ]
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.brand_id']);
    });
});
