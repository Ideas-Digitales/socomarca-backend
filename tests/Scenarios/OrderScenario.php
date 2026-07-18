<?php

namespace Tests\Scenarios;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;

class OrderScenario
{
    public array $listJsonStructure = [
        'data' => [
            '*' => [
                'id',
                'user',
                'subtotal',
                'amount',
                'status',
                'order_items',
                'order_meta',
                'random_document_number',
                'created_at',
                'updated_at'
            ]
        ]
    ];

    public array $payJsonStructure = [
        'data' => [
            'order' => [
                'id',
                'user',
                'subtotal',
                'amount',
                'status',
                'order_items' => [
                    '*' => [
                        'id',
                        'product',
                        'unit',
                        'quantity',
                        'price',
                        'subtotal',
                        'created_at',
                        'updated_at'
                    ]
                ],
                'order_meta',
                'created_at',
                'updated_at'
            ],
            'payment_url',
            'token'
        ]
    ];

    public array $payErrorJsonStructure = [
        'message',
        'order'
    ];

    public function __construct(
        public User $user,
        public Branch $branch,
    ) {}

    public static function make(): OrderScenario
    {
        $user = createUserWithPermissions(['read-own-orders', 'create-orders', 'update-orders', 'create-cart-items']);
        $branch = Branch::factory()->create(['user_id' => $user->id]);

        return new OrderScenario($user, $branch);
    }

    public function addProductToCart(float $price = 100, int $quantity = 2, string $unit = 'kg'): void
    {
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

        Price::factory()->create([
            'product_id' => $product->id,
            'unit' => $unit,
            'price' => $price,
            'valid_from' => now()->subDays(1),
            'valid_to' => null,
            'is_active' => true
        ]);

        CartItem::create([
            'user_id' => Auth::id(),
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'unit' => $unit,
        ]);
    }
}
