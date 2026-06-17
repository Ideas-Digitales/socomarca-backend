<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition()
    {
        $supercategory = Category::factory()->create(['level' => 1]);
        $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
        $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);

        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),
            'supercategory_id' => $supercategory->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'brand_id' => Brand::factory(),
            'sku' => $this->generateSku(),
            'status' => $this->faker->boolean(90),
            'image' => "/assets/global/logo_plant.png"
        ];
    }

    private function generateSku(): string
    {
        $string1 = fake()->numberBetween(10000, 99999);
        $string2 = fake()->numberBetween(10000, 99999);
        return "SKU-{$string1}-{$string2}";
    }
}
