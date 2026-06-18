<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;

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
        'extra' => [
            'supercategories',
            'categories',
            'subcategories',
        ],
        'meta',
        'filters' => [
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
        $supercategory = Category::factory()->create(['level' => 1]);
        $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
        $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);
        $brand = Brand::factory()->create();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create([
                'name' => 'Producto Estrella',
                'supercategory_id' => $supercategory->id,
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'brand_id' => $brand->id,
            ]);

        Product::factory()->has(Price::factory(['price' => 5000, 'is_active' => true]))->create(['name' => 'Otro Producto']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000],
                    'category_id' => [$category->id],
                    'subcategory_id' => [$subcategory->id],
                    'brand_id' => [$brand->id],
                    'name' => 'Estrella',
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->toHaveCount(1);
        $foundProduct = $response->json('data.0');
        expect($foundProduct['name'])->toBe('Producto Estrella');
        expect($foundProduct['category']['id'])->toBe($category->id);
        expect($foundProduct['subcategory']['id'])->toBe($subcategory->id);
        expect($foundProduct['brand']['id'])->toBe($brand->id);
    });

    it('should filter products by multiple categories', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $supercategory = Category::factory()->create(['level' => 1]);
        $category1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id, 'name' => 'Cat 1']);
        $category2 = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id, 'name' => 'Cat 2']);
        $category3 = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id, 'name' => 'Cat 3']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Cat 1', 'category_id' => $category1->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Cat 2', 'category_id' => $category2->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Cat 3', 'category_id' => $category3->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000],
                    'category_id' => [$category1->id, $category2->id],
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->toHaveCount(2);
        $ids = array_column($response->json('data'), 'category');
        $categoryIds = array_column($ids, 'id');
        expect($categoryIds)->toContain($category1->id, $category2->id);
        expect($categoryIds)->not->toContain($category3->id);
    });

    it('should filter products by multiple subcategories', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $supercategory = Category::factory()->create(['level' => 1]);
        $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id, 'name' => 'Sub 1']);
        $sub2 = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id, 'name' => 'Sub 2']);
        $sub3 = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id, 'name' => 'Sub 3']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Sub 1', 'subcategory_id' => $sub1->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Sub 2', 'subcategory_id' => $sub2->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Sub 3', 'subcategory_id' => $sub3->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000],
                    'subcategory_id' => [$sub1->id, $sub3->id],
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->toHaveCount(2);
        $ids = array_column($response->json('data'), 'subcategory');
        $subcategoryIds = array_column($ids, 'id');
        expect($subcategoryIds)->toContain($sub1->id, $sub3->id);
        expect($subcategoryIds)->not->toContain($sub2->id);
    });

    it('should filter products by multiple supercategories', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $super1 = Category::factory()->create(['level' => 1, 'name' => 'Super 1']);
        $super2 = Category::factory()->create(['level' => 1, 'name' => 'Super 2']);
        $super3 = Category::factory()->create(['level' => 1, 'name' => 'Super 3']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Super 1', 'supercategory_id' => $super1->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Super 2', 'supercategory_id' => $super2->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Product Super 3', 'supercategory_id' => $super3->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 1000, 'max' => 10000],
                    'supercategory_id' => [$super1->id, $super2->id],
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->toHaveCount(2);
        $ids = array_column($response->json('data'), 'id');
        $products = Product::whereIn('id', $ids)->get();
        $superIds = $products->pluck('supercategory_id')->toArray();
        expect($superIds)->toContain($super1->id, $super2->id);
        expect($superIds)->not->toContain($super3->id);
    });

    it('should filter products by SKU', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crear productos con SKUs diferentes
        $targetProduct = Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['sku' => 'SKU-12345']);

        Product::factory()
            ->has(Price::factory(['price' => 6000, 'is_active' => true]))
            ->create(['sku' => 'SKU-67890']);

        // Buscar por SKU usando query parameter
        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index', ['sku' => 'SKU-12345']))
            ->assertStatus(200);

        // Debería encontrar solo 1 producto
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($targetProduct->id);
        expect($response->json('data.0.sku'))->toBe('SKU-12345');
    });

    it('should return empty when SKU does not exist', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['sku' => 'SKU-12345']);

        // Buscar por SKU inexistente
        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index', ['sku' => 'SKU-NONEXISTENT']))
            ->assertStatus(200);

        // No debería encontrar productos
        expect($response->json('data'))->toBeEmpty();
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

    it('should filter products by SKU using POST search endpoint', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crear producto con SKU específico
        $targetProduct = Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['sku' => 'SKU-123']);

        // Crear otro producto
        Product::factory()
            ->has(Price::factory(['price' => 6000, 'is_active' => true]))
            ->create(['sku' => 'SKU-456']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 0, 'max' => 10000],
                    'sku' => 'SKU-123',
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($targetProduct->id);
        expect($response->json('data.0.sku'))->toBe('SKU-123');
    });

    it('should return 401 when searching by SKU without authentication', function () {
        $this->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 0, 'max' => 10000],
                    'sku' => 'SKU',
                ]
            ])
            ->assertStatus(401);
    });

    it('should return 403 when searching by SKU without read-all-products permission', function () {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => [
                    'price' => ['min' => 0, 'max' => 10000],
                    'sku' => 'SKU',
                ]
            ])
            ->assertForbidden();
    });

    it('should return categories and subcategories from all matching products', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $supercategory = Category::factory()->create(['level' => 1]);
        $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
        $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create([
                'supercategory_id' => $supercategory->id,
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
            ]);

        // Producto fuera del rango de precio (no debe aportar categorías)
        $otherSupercategory = Category::factory()->create(['level' => 1]);
        Product::factory()
            ->has(Price::factory(['price' => 50000, 'is_active' => true]))
            ->create(['supercategory_id' => $otherSupercategory->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 1000, 'max' => 10000]],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure($this->searchResponseStructure);

        expect($response->json('extra.supercategories'))->toHaveCount(1);
        expect($response->json('extra.supercategories.0.id'))->toBe($supercategory->id);
        expect($response->json('extra.categories'))->toHaveCount(1);
        expect($response->json('extra.categories.0.id'))->toBe($category->id);
        expect($response->json('extra.subcategories'))->toHaveCount(1);
        expect($response->json('extra.subcategories.0.id'))->toBe($subcategory->id);
    });

    it('should return empty categories and subcategories when no products match', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 999000, 'max' => 1000000]],
            ]);

        $response->assertStatus(200);

        expect($response->json('extra.supercategories'))->toBe([]);
        expect($response->json('extra.categories'))->toBe([]);
        expect($response->json('extra.subcategories'))->toBe([]);
    });

    it('should hide products with zero price by default', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crear producto con precio 0
        $productZeroPrice = Product::factory()
            ->has(Price::factory(['price' => 0, 'is_active' => true]))
            ->create(['name' => 'Free Product']);

        // Crear producto con precio mayor a 0
        $productNormalPrice = Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Normal Product']);

        // Por defecto, SHOW_PRODUCT_ZERO_PRICE es false, así que debe ocultarse
        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($productNormalPrice->id);
        expect($response->json('data.0.name'))->toBe('Normal Product');
    });

    it('should show products with zero price when config is enabled', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crear producto con precio 0
        $productZeroPrice = Product::factory()
            ->has(Price::factory(['price' => 0, 'is_active' => true]))
            ->create(['name' => 'Free Product']);

        // Crear producto con precio mayor a 0
        $productNormalPrice = Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true]))
            ->create(['name' => 'Normal Product']);

        // Habilitar la configuración
        config(['random.show_product_zero_price' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(2);

        $ids = array_column($response->json('data'), 'id');
        expect($ids)->toContain($productZeroPrice->id, $productNormalPrice->id);
    });

    it('should exclude inactive prices when filtering zero price products', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        // Crear producto con precio 0 inactivo
        $productInactiveZero = Product::factory()
            ->has(Price::factory(['price' => 0, 'is_active' => false]))
            ->create(['name' => 'Inactive Zero Product']);

        // Crear producto con precio 0 activo
        $productActiveZero = Product::factory()
            ->has(Price::factory(['price' => 0, 'is_active' => true]))
            ->create(['name' => 'Active Zero Product']);

        config(['random.show_product_zero_price' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($productActiveZero->id);
    });
});

describe('Product stock filter', function () {
    it('should not return products with stock equal to 0 in index', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 0]))
            ->create(['name' => 'No Stock Product']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 50]))
            ->create(['name' => 'In Stock Product']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index'));

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        expect($names)->not->toContain('No Stock Product');
        expect($names)->toContain('In Stock Product');
    });

    it('should not return products with stock less than 0 in index', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => -5]))
            ->create(['name' => 'Negative Stock Product']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 50]))
            ->create(['name' => 'In Stock Product']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('products.index'));

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        expect($names)->not->toContain('Negative Stock Product');
        expect($names)->toContain('In Stock Product');
    });

    it('should not return products with stock equal to 0 in search', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 0]))
            ->create(['name' => 'No Stock Product']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 50]))
            ->create(['name' => 'In Stock Product']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        expect($names)->not->toContain('No Stock Product');
        expect($names)->toContain('In Stock Product');
    });

    it('should not return products with stock less than 0 in search', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => -10]))
            ->create(['name' => 'Negative Stock Product']);

        Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 50]))
            ->create(['name' => 'In Stock Product']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        expect($names)->not->toContain('Negative Stock Product');
        expect($names)->toContain('In Stock Product');
    });

    it('should return products with stock greater than 0', function () {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('read-all-products');
        Product::truncate();

        $product = Product::factory()
            ->has(Price::factory(['price' => 5000, 'is_active' => true, 'stock' => 100]))
            ->create(['name' => 'Stocked Product']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('products.search'), [
                'filters' => ['price' => ['min' => 0, 'max' => 10000]],
            ]);

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($product->id);
        expect($response->json('data.0.stock'))->toBe(100);
    });
});
