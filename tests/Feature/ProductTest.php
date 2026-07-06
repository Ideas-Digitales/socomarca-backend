<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Gets the products search response structure
 * @return array
 */
function getSearchResponseStructure()
{
    return [
        "data" => [
            "*" => [
                "id",
                "name",
                "unit",
                "price",
                "stock",
                "image",
                "sku",
                "is_favorite",
                "category" => ["id", "name"],
                "subcategory" => ["id", "name"],
                "brand" => ["id", "name"],
            ],
        ],
        "extra" => ["supercategories", "categories", "subcategories"],
        "meta",
        "filters" => ["min_price", "max_price"],
    ];
}

describe("Product list endpoint", function (): void {
    it("should return 401 without authentication", function (): void {
        getJson(route("products.index"))->assertStatus(401);
    });

    it(
        "should return 403 when not having the 'read-all-products' permission",
        function (): void {
            $user = App\Models\User::factory()->create();
            actingAs($user, "sanctum")
                ->getJson(route("products.index"))
                ->assertForbidden();
        },
    );

    it(
        "should response a product list filtered by price range",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();
            $minSearch = 60000;
            $maxSearch = 90000;

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 75000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["status" => true]);
            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 40000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["status" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => [
                        "price" => [
                            "min" => $minSearch,
                            "max" => $maxSearch,
                        ],
                    ],
                ],
            );

            $response
                ->assertStatus(200)
                ->assertJsonStructure(getSearchResponseStructure());

            expect($response->json("data"))->not->toBeEmpty();
            foreach ($response->json("data") as $product) {
                expect($product["price"])->toBeGreaterThanOrEqual($minSearch);
                expect($product["price"])->toBeLessThanOrEqual($maxSearch);
            }
        },
    );

    it(
        "should apply optional filters for category, subcategory, brand, and name",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();
            $superCategory = Category::factory()->create(["level" => 1]);
            $category = Category::factory()->create([
                "level" => 2,
                "parent_category_id" => $superCategory->id,
            ]);
            $subcategory = Category::factory()->create([
                "level" => 3,
                "parent_category_id" => $category->id,
            ]);
            $brand = Brand::factory()->create();

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create([
                    "name" => "Producto Estrella",
                    "supercategory_id" => $superCategory->id,
                    "category_id" => $category->id,
                    "subcategory_id" => $subcategory->id,
                    "brand_id" => $brand->id,
                    "status" => true,
                ]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Otro Producto", "status" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => [
                        "price" => ["min" => 1000, "max" => 10000],
                        "category_id" => [$category->id],
                        "subcategory_id" => [$subcategory->id],
                        "brand_id" => [$brand->id],
                        "name" => "Estrella",
                    ],
                ],
            );

            $response
                ->assertStatus(200)
                ->assertJsonStructure(getSearchResponseStructure());

            expect($response->json("data"))->toHaveCount(1);
            $foundProduct = $response->json("data.0");
            expect($foundProduct["name"])->toBe("Producto Estrella");
            expect($foundProduct["category"]["id"])->toBe($category->id);
            expect($foundProduct["subcategory"]["id"])->toBe($subcategory->id);
            expect($foundProduct["brand"]["id"])->toBe($brand->id);
        },
    );

    it("should filter products by multiple categories", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $superCategory = Category::factory()->create(["level" => 1]);
        $category1 = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
            "name" => "Cat 1",
        ]);
        $category2 = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
            "name" => "Cat 2",
        ]);
        $category3 = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
            "name" => "Cat 3",
        ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Cat 1",
                "category_id" => $category1->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Cat 2",
                "category_id" => $category2->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Cat 3",
                "category_id" => $category3->id,
                "status" => true,
            ]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "price" => ["min" => 1000, "max" => 10000],
                    "category_id" => [$category1->id, $category2->id],
                ],
            ],
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure(getSearchResponseStructure());

        expect($response->json("data"))->toHaveCount(2);
        $ids = array_column($response->json("data"), "category");
        $categoryIds = array_column($ids, "id");
        expect($categoryIds)->toContain($category1->id, $category2->id);
        expect($categoryIds)->not->toContain($category3->id);
    });

    it("should filter products by multiple subcategories", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $superCategory = Category::factory()->create(["level" => 1]);
        $category = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
        ]);
        $sub1 = Category::factory()->create([
            "level" => 3,
            "parent_category_id" => $category->id,
            "name" => "Sub 1",
        ]);
        $sub2 = Category::factory()->create([
            "level" => 3,
            "parent_category_id" => $category->id,
            "name" => "Sub 2",
        ]);
        $sub3 = Category::factory()->create([
            "level" => 3,
            "parent_category_id" => $category->id,
            "name" => "Sub 3",
        ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Sub 1",
                "subcategory_id" => $sub1->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Sub 2",
                "subcategory_id" => $sub2->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Sub 3",
                "subcategory_id" => $sub3->id,
                "status" => true,
            ]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "price" => ["min" => 1000, "max" => 10000],
                    "subcategory_id" => [$sub1->id, $sub3->id],
                ],
            ],
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure(getSearchResponseStructure());

        expect($response->json("data"))->toHaveCount(2);
        $ids = array_column($response->json("data"), "subcategory");
        $subcategoryIds = array_column($ids, "id");
        expect($subcategoryIds)->toContain($sub1->id, $sub3->id);
        expect($subcategoryIds)->not->toContain($sub2->id);
    });

    it("should filter products by multiple superCategories", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $super1 = Category::factory()->create([
            "level" => 1,
            "name" => "Super 1",
        ]);
        $super2 = Category::factory()->create([
            "level" => 1,
            "name" => "Super 2",
        ]);
        $super3 = Category::factory()->create([
            "level" => 1,
            "name" => "Super 3",
        ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Super 1",
                "supercategory_id" => $super1->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Super 2",
                "supercategory_id" => $super2->id,
                "status" => true,
            ]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create([
                "name" => "Product Super 3",
                "supercategory_id" => $super3->id,
                "status" => true,
            ]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "price" => ["min" => 1000, "max" => 10000],
                    "supercategory_id" => [$super1->id, $super2->id],
                ],
            ],
        );

        $response
            ->assertStatus(200)
            ->assertJsonStructure(getSearchResponseStructure());

        expect($response->json("data"))->toHaveCount(2);
        $ids = array_column($response->json("data"), "id");
        $products = Product::whereIn("id", $ids)->get();
        $superIds = $products->pluck("supercategory_id")->toArray();
        expect($superIds)->toContain($super1->id, $super2->id);
        expect($superIds)->not->toContain($super3->id);
    });

    it("should filter products by SKU", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $targetProduct = Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["sku" => "SKU-12345", "status" => true]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 6000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["sku" => "SKU-67890", "status" => true]);

        $response = actingAs($user, "sanctum")
            ->getJson(route("products.index", ["sku" => "SKU-12345"]))
            ->assertStatus(200);

        expect($response->json("data"))->toHaveCount(1);
        expect($response->json("data.0.id"))->toBe($targetProduct->id);
        expect($response->json("data.0.sku"))->toBe("SKU-12345");
    });

    it("should return empty when SKU does not exist", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["sku" => "SKU-12345", "status" => true]);

        $response = actingAs($user, "sanctum")
            ->getJson(route("products.index", ["sku" => "SKU-NONEXISTENT"]))
            ->assertStatus(200);

        expect($response->json("data"))->toBeEmpty();
    });

    it(
        "should apply sorting by price, stock, category_name and id",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $catA = Category::factory()->create(["name" => "Alimentos"]);
            $catB = Category::factory()->create(["name" => "Bebidas"]);

            $p1 = Product::factory()
                ->for($catA)
                ->has(
                    Price::factory([
                        "price" => 1000,
                        "stock" => 5,
                        "is_active" => true,
                        "unit" => "kg",
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Producto 1", "status" => true]);
            $p2 = Product::factory()
                ->for($catB)
                ->has(
                    Price::factory([
                        "price" => 2000,
                        "stock" => 10,
                        "is_active" => true,
                        "unit" => "kg",
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Producto 2", "status" => true]);
            $p3 = Product::factory()
                ->for($catA)
                ->has(
                    Price::factory([
                        "price" => 1500,
                        "stock" => 7,
                        "is_active" => true,
                        "unit" => "kg",
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Producto 3", "status" => true]);

            $response = actingAs($user, "sanctum")
                ->getJson(
                    route("products.index", [
                        "sort" => "price",
                        "sort_direction" => "asc",
                    ]),
                )
                ->assertStatus(200);

            $prices = array_column($response->json("data"), "price");
            expect($prices)->toBe([1000, 1500, 2000]);

            $response = actingAs($user, "sanctum")
                ->getJson(
                    route("products.index", [
                        "sort" => "stock",
                        "sort_direction" => "desc",
                    ]),
                )
                ->assertStatus(200);
            $stocks = array_column($response->json("data"), "stock");
            expect($stocks)->toBe([10, 7, 5]);

            $response = actingAs($user, "sanctum")->getJson(
                "/api/products?sort=category_name&sort_direction=asc",
            );
            $response->assertStatus(200);
            $categories = array_column($response->json("data"), "category");
            $categoryNames = array_column($categories, "name");
            expect($categoryNames)->toBe(["Alimentos", "Alimentos", "Bebidas"]);

            $response = actingAs($user, "sanctum")
                ->getJson(
                    route("products.index", [
                        "sort" => "id",
                        "sort_direction" => "desc",
                    ]),
                )
                ->assertStatus(200);
            $ids = array_column($response->json("data"), "id");
            rsort($ids);
            expect($response->json("data.0.id"))->toBe($ids[0]);
        },
    );

    it("should apply is_favorite filter when provided", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        $favoriteList = FavoriteList::factory()->create([
            "user_id" => $user->id,
        ]);
        $favoriteProduct = Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["status" => true]);
        Favorite::factory()->create([
            "favorite_list_id" => $favoriteList->id,
            "product_id" => $favoriteProduct->id,
        ]);

        $nonFavoriteProduct = Product::factory()
            ->has(
                Price::factory([
                    "price" => 6000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["status" => true]);

        $responseFavorite = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "price" => ["min" => 1000, "max" => 10000], // Rango obligatorio
                    "is_favorite" => true,
                ],
            ],
        );

        $responseFavorite->assertStatus(200);
        // Debería encontrar solo el producto favorito
        expect($responseFavorite->json("data"))->toHaveCount(1);
        expect($responseFavorite->json("data.0.id"))->toBe(
            $favoriteProduct->id,
        );
        expect($responseFavorite->json("data.0.is_favorite"))->toBeTrue();

        $responseNonFavorite = actingAs($user, "sanctum")->postJson(
            "/api/products/search",
            [
                "filters" => [
                    "price" => ["min" => 1000, "max" => 10000], // Rango obligatorio
                    "is_favorite" => false,
                ],
            ],
        );

        $responseNonFavorite->assertStatus(200);
        // Debería encontrar solo el producto que NO es favorito
        expect($responseNonFavorite->json("data"))->toHaveCount(1);
        expect($responseNonFavorite->json("data.0.id"))->toBe(
            $nonFavoriteProduct->id,
        );
        expect($responseNonFavorite->json("data.0.is_favorite"))->toBeFalse();
    });
});

describe("Product search endpoint", function (): void {
    it("should fail if price range is missing", function (): void {
        $user = App\Models\User::factory()->create();
        $user->givePermissionTo("read-all-products");
        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "name" => "un producto cualquiera",
                ],
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrorFor("filters.price");
    });

    it("should fail validation if brand_id is not an array", function (): void {
        $user = App\Models\User::factory()->create();
        $user->givePermissionTo("read-all-products");
        $brand = Brand::factory()->create();

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => [
                    "price" => ["min" => 0, "max" => 20000],
                    "brand_id" => $brand->id,
                ],
            ],
        );
        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(["filters.brand_id"]);
    });

    it(
        "should filter products by SKU using POST search endpoint",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $targetProduct = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["sku" => "SKU-123", "status" => true]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 6000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["sku" => "SKU-456", "status" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => [
                        "price" => ["min" => 0, "max" => 10000],
                        "sku" => "SKU-123",
                    ],
                ],
            );

            $response
                ->assertStatus(200)
                ->assertJsonStructure(getSearchResponseStructure());

            expect($response->json("data"))->toHaveCount(1);
            expect($response->json("data.0.id"))->toBe($targetProduct->id);
            expect($response->json("data.0.sku"))->toBe("SKU-123");
        },
    );

    it(
        "should return 401 when searching by SKU without authentication",
        function (): void {
            postJson(route("products.search"), [
                "filters" => [
                    "price" => ["min" => 0, "max" => 10000],
                    "sku" => "SKU",
                ],
            ])->assertStatus(401);
        },
    );

    it(
        "should return 403 when searching by SKU without read-all-products permission",
        function (): void {
            $user = App\Models\User::factory()->create();

            actingAs($user, "sanctum")
                ->postJson(route("products.search"), [
                    "filters" => [
                        "price" => ["min" => 0, "max" => 10000],
                        "sku" => "SKU",
                    ],
                ])
                ->assertForbidden();
        },
    );

    it(
        "should return categories and subcategories from all matching products",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $superCategory = Category::factory()->create(["level" => 1]);
            $category = Category::factory()->create([
                "level" => 2,
                "parent_category_id" => $superCategory->id,
            ]);
            $subcategory = Category::factory()->create([
                "level" => 3,
                "parent_category_id" => $category->id,
            ]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create([
                    "supercategory_id" => $superCategory->id,
                    "category_id" => $category->id,
                    "subcategory_id" => $subcategory->id,
                    "status" => true,
                ]);

            $otherSuperCategory = Category::factory()->create(["level" => 1]);
            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 50000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create([
                    "supercategory_id" => $otherSuperCategory->id,
                    "status" => true,
                ]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => ["price" => ["min" => 1000, "max" => 10000]],
                ],
            );

            $response
                ->assertStatus(200)
                ->assertJsonStructure(getSearchResponseStructure());

            expect($response->json("extra.supercategories"))->toHaveCount(1);
            expect($response->json("extra.supercategories.0.id"))->toBe(
                $superCategory->id,
            );
            expect($response->json("extra.categories"))->toHaveCount(1);
            expect($response->json("extra.categories.0.id"))->toBe(
                $category->id,
            );
            expect($response->json("extra.subcategories"))->toHaveCount(1);
            expect($response->json("extra.subcategories.0.id"))->toBe(
                $subcategory->id,
            );
        },
    );

    it(
        "should return empty categories and subcategories when no products match",
        function (): void {
            $user = App\Models\User::factory()->create();
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => [
                        "price" => ["min" => 999000, "max" => 1000000],
                    ],
                ],
            );

            $response->assertStatus(200);

            expect($response->json("extra.supercategories"))->toBe([]);
            expect($response->json("extra.categories"))->toBe([]);
            expect($response->json("extra.subcategories"))->toBe([]);
        },
    );

    it("should hide products with zero price by default", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $productZeroPrice = Product::factory()
            ->has(
                Price::factory([
                    "price" => 0,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Free Product", "status" => true]);

        $productNormalPrice = Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Normal Product", "status" => true]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => ["price" => ["min" => 0, "max" => 10000]],
            ],
        );

        $response->assertStatus(200);
        expect($response->json("data"))->toHaveCount(1);
        expect($response->json("data.0.id"))->toBe($productNormalPrice->id);
        expect($response->json("data.0.name"))->toBe("Normal Product");
    });

    it(
        "should show products with zero price when config is enabled",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $productZeroPrice = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 0,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Free Product", "status" => true]);

            $productNormalPrice = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Normal Product", "status" => true]);

            config(["random.show_product_zero_price" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => ["price" => ["min" => 0, "max" => 10000]],
                ],
            );

            $response->assertStatus(200);
            expect($response->json("data"))->toHaveCount(2);

            $ids = array_column($response->json("data"), "id");
            expect($ids)->toContain(
                $productZeroPrice->id,
                $productNormalPrice->id,
            );
        },
    );

    it(
        "should exclude inactive prices when filtering zero price products",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            $productInactiveZero = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 0,
                        "is_active" => false,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Inactive Zero Product", "status" => true]);

            $productActiveZero = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 0,
                        "is_active" => true,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "Active Zero Product", "status" => true]);

            config(["random.show_product_zero_price" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => ["price" => ["min" => 0, "max" => 10000]],
                ],
            );

            $response->assertStatus(200);
            expect($response->json("data"))->toHaveCount(1);
            expect($response->json("data.0.id"))->toBe($productActiveZero->id);
        },
    );
});

describe("Product stock filter", function (): void {
    it(
        "should not return products with stock equal to 0 in index",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 0,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "No Stock Product", "status" => true]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "In Stock Product", "status" => true]);

            $response = actingAs($user, "sanctum")->getJson(
                route("products.index"),
            );

            $response->assertStatus(200);
            $names = array_column($response->json("data"), "name");
            expect($names)->not->toContain("No Stock Product");
            expect($names)->toContain("In Stock Product");
        },
    );

    it(
        "should not return products with stock less than 0 in index",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => -5,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create([
                    "name" => "Negative Stock Product",
                    "status" => true,
                ]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "In Stock Product", "status" => true]);

            $response = actingAs($user, "sanctum")->getJson(
                route("products.index"),
            );

            $response->assertStatus(200);
            $names = array_column($response->json("data"), "name");
            expect($names)->not->toContain("Negative Stock Product");
            expect($names)->toContain("In Stock Product");
        },
    );

    it(
        "should not return products with stock equal to 0 in search",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 0,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "No Stock Product", "status" => true]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "In Stock Product", "status" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => ["price" => ["min" => 0, "max" => 10000]],
                ],
            );

            $response->assertStatus(200);
            $names = array_column($response->json("data"), "name");
            expect($names)->not->toContain("No Stock Product");
            expect($names)->toContain("In Stock Product");
        },
    );

    it(
        "should not return products with stock less than 0 in search",
        function (): void {
            $priceListCode = "01P";
            $user = App\Models\User::factory()->create([
                "prices_lists" => [$priceListCode],
            ]);
            $user->givePermissionTo("read-all-products");
            Product::truncate();

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => -10,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create([
                    "name" => "Negative Stock Product",
                    "status" => true,
                ]);

            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $priceListCode,
                    ]),
                )
                ->create(["name" => "In Stock Product", "status" => true]);

            $response = actingAs($user, "sanctum")->postJson(
                route("products.search"),
                [
                    "filters" => ["price" => ["min" => 0, "max" => 10000]],
                ],
            );

            $response->assertStatus(200);
            $names = array_column($response->json("data"), "name");
            expect($names)->not->toContain("Negative Stock Product");
            expect($names)->toContain("In Stock Product");
        },
    );

    it("should return products with stock greater than 0", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        $product = Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "stock" => 100,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Stocked Product", "status" => true]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => ["price" => ["min" => 0, "max" => 10000]],
            ],
        );

        $response->assertStatus(200);
        expect($response->json("data"))->toHaveCount(1);
        expect($response->json("data.0.id"))->toBe($product->id);
        expect($response->json("data.0.stock"))->toBe(100);
    });
});

describe("Product active filter", function (): void {
    it("should not return inactive products in index", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "stock" => 50,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Inactive Product", "status" => false]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "stock" => 50,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Active Product", "status" => true]);

        $response = actingAs($user, "sanctum")->getJson(
            route("products.index"),
        );

        $response->assertStatus(200);
        $names = array_column($response->json("data"), "name");
        expect($names)->not->toContain("Inactive Product");
        expect($names)->toContain("Active Product");
    });

    it("should not return inactive products in search", function (): void {
        $priceListCode = "01P";
        $user = App\Models\User::factory()->create([
            "prices_lists" => [$priceListCode],
        ]);
        $user->givePermissionTo("read-all-products");
        Product::truncate();

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "stock" => 50,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Inactive Product", "status" => false]);

        Product::factory()
            ->has(
                Price::factory([
                    "price" => 5000,
                    "is_active" => true,
                    "stock" => 50,
                    "price_list_id" => $priceListCode,
                ]),
            )
            ->create(["name" => "Active Product", "status" => true]);

        $response = actingAs($user, "sanctum")->postJson(
            route("products.search"),
            [
                "filters" => ["price" => ["min" => 0, "max" => 10000]],
            ],
        );

        $response->assertStatus(200);
        $names = array_column($response->json("data"), "name");
        expect($names)->not->toContain("Inactive Product");
        expect($names)->toContain("Active Product");
    });
});

describe('"byUserPrices" scope tests', function (): void {
    it("should return allowed user prices lists", function (): void {
        $allowedPriceListCode = "ALLOWED";
        $otherPriceListCode = "OTHER";

        $user = App\Models\User::factory()->create([
            "prices_lists" => [$allowedPriceListCode],
        ]);
        $user->givePermissionTo("read-all-products");

        Product::truncate();

        $superCategory = Category::factory()->create(["level" => 1]);
        $category = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
        ]);
        $subcategory = Category::factory()->create([
            "level" => 3,
            "parent_category_id" => $category->id,
        ]);

        $expectedProducts = [];
        for ($i = 0; $i < 3; $i++) {
            $product = Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $allowedPriceListCode,
                    ]),
                )
                ->create([
                    "supercategory_id" => $superCategory->id,
                    "category_id" => $category->id,
                    "subcategory_id" => $subcategory->id,
                    "status" => true,
                ]);
            $expectedProducts[] = $product->id;
        }

        for ($i = 0; $i < 3; $i++) {
            Product::factory()
                ->has(
                    Price::factory([
                        "price" => 5000,
                        "is_active" => true,
                        "stock" => 50,
                        "price_list_id" => $otherPriceListCode,
                    ]),
                )
                ->create([
                    "supercategory_id" => $superCategory->id,
                    "category_id" => $category->id,
                    "subcategory_id" => $subcategory->id,
                    "status" => true,
                ]);
        }

        $productsResponse = actingAs($user, "sanctum")
            ->getJson(route("products.index"))
            ->json("data");

        $products = collect($productsResponse);
        $productIds = $products->pluck("id")->toArray();

        foreach ($expectedProducts as $expectedId) {
            expect($productIds)->toContain($expectedId);
        }

        expect($productIds)->toHaveCount(3);
    });

    it("should return all the products variants", function (): void {
        $allowedPriceListCode = "ALLOWED";
        $otherPriceListCode = "OTHER";

        $user = App\Models\User::factory()->create([
            "prices_lists" => [$allowedPriceListCode],
        ]);
        $user->givePermissionTo("read-all-products");

        $superCategory = Category::factory()->create(["level" => 1]);
        $category = Category::factory()->create([
            "level" => 2,
            "parent_category_id" => $superCategory->id,
        ]);
        $subcategory = Category::factory()->create([
            "level" => 3,
            "parent_category_id" => $category->id,
        ]);

        $expectedVariants = [];
        $excludedVariants = [];
        for ($i = 0; $i < 10; $i++) {
            $product = Product::factory([
                "supercategory_id" => $superCategory->id,
                "category_id" => $category->id,
                "subcategory_id" => $subcategory->id,
                "status" => true,
            ])->create();
            for ($j = 0; $j < 5; $j++) {
                $stock = fake()->numberBetween(1, 999);
                $price = fake()->numberBetween(1, 999999);
                $unit = fake()->randomElement(["g", "kg", "un", "l", "ml"]);
                $priceListCode = fake()->randomElement([
                    $allowedPriceListCode,
                    $otherPriceListCode,
                ]);
                Price::factory([
                    "price" => $price,
                    "is_active" => true,
                    "stock" => $stock,
                    "unit" => $unit,
                    "price_list_id" => $priceListCode,
                ])
                    ->for($product)
                    ->create();

                if ($priceListCode === $allowedPriceListCode) {
                    $expectedVariants[] = "{$product->id}-{$price}-{$stock}-{$unit}";
                } else {
                    $excludedVariants[] = "{$product->id}-{$price}-{$stock}-{$unit}";
                }
            }
        }

        $productsResponse = actingAs($user, "sanctum")
            ->getJson(route("products.index", ["per_page" => 100]))
            ->Json("data");
        $productsResponse = array_map(
            fn($e) => "{$e["id"]}-{$e["price"]}-{$e["stock"]}-{$e["unit"]}",
            $productsResponse,
        );

        expect($productsResponse)->toHaveCount(count($expectedVariants));
        expect(array_diff($expectedVariants, $productsResponse))->toBeEmpty();
        expect(
            array_intersect($excludedVariants, $productsResponse),
        )->toBeEmpty();
    });
});
