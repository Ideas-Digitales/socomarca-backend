<?php

namespace Tests\Scenarios;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;

class CartScenario
{
    public function __construct(
        public User $user,
        public Product $product,
        public Price $price,
    ) {}

    public static function make(): CartScenario
    {
        $user = createUserWithPermissions(['read-own-cart']);

        $supercategory = Category::factory()->create(['level' => 1]);
        $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
        $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);
        $brand = Brand::factory()->create();

        $product = Product::factory()->create([
            'supercategory_id' => $supercategory->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'brand_id' => $brand->id
        ]);

        $price = Price::factory()->create([
            'product_id' => $product->id,
            'unit' => 'kg',
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
            'valid_from' => now()->subDays(1),
            'valid_to' => null
        ]);

        return new CartScenario($user, $product, $price);
    }
}
