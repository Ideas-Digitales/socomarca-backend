<?php

namespace Tests\Scenarios;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;

/**
 * Category tree holding one product filed under a supercategory that is not the parent
 * of its category.
 *
 * Random ERP resolves the superfamily (FMPR) and the family (PFPR) codes independently,
 * so the two can disagree; product 10956 is the real case. Every category here is
 * correct — the only wrong row is that product.
 *
 * @see \App\Jobs\SyncRandomProducts
 * @see \App\Http\Controllers\Api\CategoryController::index()
 */
class WrongCategoryProductAssociationScenario
{
    /**
     * Structure of GET /api/categories, per the `SuperCategoryResource` schema.
     */
    public array $indexJsonStructure = [
        "*" => [
            "id",
            "name",
            "description",
            "code",
            "level",
            "key",
            "categories_count",
            "products_count",
            "created_at",
            "updated_at",
            "categories" => [
                "*" => [
                    "id",
                    "name",
                    "description",
                    "code",
                    "level",
                    "key",
                    "subcategories_count",
                    "products_count",
                    "created_at",
                    "updated_at",
                    "subcategories" => [
                        "*" => [
                            "id",
                            "name",
                            "description",
                            "code",
                            "level",
                            "key",
                            "products_count",
                            "created_at",
                            "updated_at",
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(
        public User $user,
        public Category $superFamily0001,
        public Category $family0003,
        public Category $family0004,
        public Category $superFamily0003,
        public Category $family0002,
        public Category $superFamily0009,
        public Category $family0009,
        public Category $subFamily0009,
        public Product $misfiledProduct1,
        public Product $wellFiledProduct1,
        public Product $wellFiledProduct2,
    ) {}

    public static function make(): WrongCategoryProductAssociationScenario
    {
        $user = createUserWithPermissions([
            "read-all-categories",
            "read-price-list-products",
        ]);
        $user->update(["prices_lists" => [getPriceListCode()]]);

        $superFamily0001 = self::category("Categoría Padre Nivel 1. 0001", 1, "0001");
        $family0003 = self::category("Categoría Hijo Nivel 2. 0003", 2, "0003", $superFamily0001);
        $family0004 = self::category("Categoría Hijo Nivel 2. 0004", 2, "0004", $superFamily0001);

        $superFamily0003 = self::category("Categoría Padre Nivel 1. 0003", 1, "0003");
        $family0002 = self::category("Categoría Hijo Nivel 2. 0002", 2, "0002", $superFamily0003);

        $superFamily0009 = self::category("Categoría Padre Nivel 1. 0009", 1, "0009");
        $family0009 = self::category("Categoría Hijo Nivel 2. 0009", 2, "0009", $superFamily0009);
        $subFamily0009 = self::category("Categoría Nieto Nivel 3. 0009", 3, "0009", $family0009);

        // FMPR 0003 and PFPR 0003 are two unrelated rows: the family belongs to FMPR 0001.
        $misfiledProduct1 = self::product("10956", $superFamily0003, $family0003);

        $wellFiledProduct1 = self::product("11956", $superFamily0001, $family0004);
        $wellFiledProduct2 = self::product("11957", $superFamily0009, $family0009, $subFamily0009);

        return new WrongCategoryProductAssociationScenario(
            $user,
            $superFamily0001,
            $family0003,
            $family0004,
            $superFamily0003,
            $family0002,
            $superFamily0009,
            $family0009,
            $subFamily0009,
            $misfiledProduct1,
            $wellFiledProduct1,
            $wellFiledProduct2,
        );
    }

    /**
     * Every category id present in a GET /api/categories response, at any of the three
     * levels. Ids come from a single table, so one id identifies one node.
     *
     * @param array $tree The decoded response body
     * @return array<int, int>
     */
    public function renderedCategoryIds(array $tree): array
    {
        return collect($tree)
            ->flatMap(fn ($supercategory) => [
                $supercategory["id"],
                ...collect($supercategory["categories"])->flatMap(fn ($category) => [
                    $category["id"],
                    ...array_column($category["subcategories"], "id"),
                ]),
            ])
            ->all();
    }

    private static function category(
        string $name,
        int $level,
        string $code,
        ?Category $parent = null,
    ): Category {
        return Category::factory()->create([
            "name" => $name,
            "level" => $level,
            "code" => $code,
            "key" => $code,
            "enabled" => true,
            "parent_category_id" => $parent?->id,
        ]);
    }

    /**
     * An active product priced on the user's list, filed exactly where it is told to be.
     *
     * Built row by row instead of through ProductFactory, whose definition() creates a
     * supercategory, a category, a subcategory and a brand of its own on every call.
     */
    private static function product(
        string $sku,
        Category $supercategory,
        Category $category,
        ?Category $subcategory = null,
    ): Product {
        $product = Product::create([
            "random_product_id" => $sku,
            "name" => "Producto {$sku}",
            "sku" => $sku,
            "status" => true,
            "supercategory_id" => $supercategory->id,
            "category_id" => $category->id,
            "subcategory_id" => $subcategory?->id,
        ]);

        Price::create([
            "product_id" => $product->id,
            "random_product_id" => $sku,
            "price_list_id" => getPriceListCode(),
            "unit" => "un",
            "price" => 1000,
            "stock" => 1000,
            "is_active" => true,
            "valid_from" => now()->subDays(10),
        ]);

        return $product;
    }
}
