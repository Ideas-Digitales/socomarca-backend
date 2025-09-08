<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Price;
use App\Models\ProductStock;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Storage;

class ProductSeeder extends Seeder
{
    public function run(): void
    {

        $json = Storage::disk('local')->get('fake_seed_data/products.json');
        $categories = json_decode($json, true);

        foreach ($categories as $catData) {
            
            $category = Category::firstOrCreate(
                ['name' => $catData['name']],
                [
                    'description' => $catData['name'],
                    'code' => fake()->regexify('[A-Z]{10}'),
                    'level' => 1,
                    'key' => fake()->regexify('[A-Z]{4}'),
                ]
            );

            foreach ($catData['subcategories'] as $subcatData) {
                // Crea la subcategoría o la busca si ya existe
                $subcategory = Subcategory::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'name'        => $subcatData['name'],
                    ],
                    [
                        'description' => $subcatData['name'],
                        'code' => fake()->regexify('[A-Z]{10}'),
                        'level' => 1,
                        'key' => fake()->regexify('[A-Z]{4}'),
                    ]
                );

                foreach ($subcatData['products'] as $prodData) {
                    $sku = $prodData['sku'] ?? fake()->unique()->numerify('SKU#####');
                    $name = $prodData['name'] ?? fake()->words(3, true);
                    $brandName = $prodData['brand'] ?? fake()->company();
                    $unit = $prodData['unit'] ?? 'un';

                    $brand = Brand::firstOrCreate(
                        ['name' => $brandName],
                        ['description' => $brandName],
                        ['logo_url' => "https://cdn-icons-png.flaticon.com/512/5130/5130770.png"]
                    );

                    $product = Product::firstOrCreate(
                        ['sku' => $sku],
                        [
                            'name' => $name,
                            'description' => $name,
                            'category_id' => $category->id,
                            'subcategory_id' => $subcategory->id,
                            'brand_id' => $brand->id,
                            'status' => true,
                        ]
                    );

                    Price::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'unit' => $unit,
                            'price_list_id' => 1, 
                        ],
                        [
                            'price' => random_int(1000, 50000),
                            'is_active' => true,
                        ]
                    );

                    // Crear stock en las bodegas para este producto
                    $this->createProductStock($product, $unit);
                }
            }
        }
    }

    /**
     * Create stock for a product across different warehouses
     */
    private function createProductStock(Product $product, string $unit): void
    {
        $warehouses = Warehouse::where('is_active', true)->get();
        
        foreach ($warehouses as $warehouse) {
            // No todos los productos tienen stock en todas las bodegas
            // Algunas bodegas pueden tener 0 stock
            $hasStock = fake()->boolean(70); // 70% probabilidad de tener stock
            
            if ($hasStock) {
                $stockAmount = fake()->numberBetween(5, 100);
            } else {
                $stockAmount = 0;
            }

            ProductStock::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'unit' => $unit,
                ],
                [
                    'stock' => $stockAmount,
                    'reserved_stock' => 0,
                    'min_stock' => $hasStock ? fake()->numberBetween(1, 10) : null,
                ]
            );
        }
    }
}
