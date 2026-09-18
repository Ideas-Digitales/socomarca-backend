<?php

namespace Tests\Scenarios;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;

/**
 * Category tree holding one product filed under a subcategory that is not a child of its
 * category — the second link of the chain, mirror of
 * WrongCategoryProductAssociationScenario.
 *
 * Every category here is correct and every level-2 node is held up by a well filed
 * product of its own, so the only node the strict association can take away is the
 * subcategory the misfiled product claims.
 *
 * @see \Tests\Scenarios\WrongCategoryProductAssociationScenario
 * @see \App\Models\Product::scopeConsistentlyFiled()
 */
class WrongSubcategoryProductAssociationScenario
{
    public function __construct(
        public User $user,
        public Category $superFamily,
        public Category $familyA,
        public Category $familyB,
        public Category $subFamilyA,
        public Category $subFamilyB,
        public Product $misfiledProduct,
        public Product $wellFiledInFamilyA,
        public Product $wellFiledInFamilyB,
    ) {}

    public static function make(): WrongSubcategoryProductAssociationScenario
    {
        $user = createUserWithPermissions([
            "read-all-categories",
            "read-price-list-products",
        ]);
        $user->update(["prices_lists" => [getPriceListCode()]]);

        $superFamily = self::category("Categoría Padre Nivel 1", 1, "0100");
        $familyA = self::category("Categoría Hijo Nivel 2. A", 2, "0101", $superFamily);
        $familyB = self::category("Categoría Hijo Nivel 2. B", 2, "0102", $superFamily);
        $subFamilyA = self::category("Categoría Nieto Nivel 3. A", 3, "0101", $familyA);
        $subFamilyB = self::category("Categoría Nieto Nivel 3. B", 3, "0102", $familyB);

        // Files itself in family A but claims a subfamily of family B.
        $misfiledProduct = self::product("20956", $superFamily, $familyA, $subFamilyB);

        // Keep both families alive on their own, so the only node left hanging from the
        // misfiled product is subfamily B.
        $wellFiledInFamilyA = self::product("21956", $superFamily, $familyA, $subFamilyA);
        $wellFiledInFamilyB = self::product("21957", $superFamily, $familyB);

        return new WrongSubcategoryProductAssociationScenario(
            $user,
            $superFamily,
            $familyA,
            $familyB,
            $subFamilyA,
            $subFamilyB,
            $misfiledProduct,
            $wellFiledInFamilyA,
            $wellFiledInFamilyB,
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
