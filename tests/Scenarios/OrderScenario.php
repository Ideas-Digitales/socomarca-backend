<?php

namespace Tests\Scenarios;

use App\Enums\BranchType;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class OrderScenario
{
    /**
     * Price list of the user and, by default, of its secondary branches.
     */
    public const PRICE_LIST = 'MIL';

    public array $listJsonStructure = [
        'data' => [
            '*' => [
                'id',
                'user',
                'subtotal',
                'vat',
                'vat_amount',
                'total',
                'amount',
                'status',
                'order_items',
                'order_meta',
                'customer' => ['id', 'name', 'user_code', 'branch_code', 'branch_type'],
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
                'vat',
                'vat_amount',
                'total',
                'amount',
                'status',
                'order_items' => [
                    '*' => [
                        'id',
                        'product',
                        'unit',
                        'quantity',
                        'price',
                        'price_list_id',
                        'subtotal',
                        'vat',
                        'vat_amount',
                        'total',
                        'created_at',
                        'updated_at'
                    ]
                ],
                'order_meta',
                'customer' => ['id', 'name', 'user_code', 'branch_code', 'branch_type'],
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
    ) {}

    /**
     * The user is the primary branch of a Random entity.
     */
    public static function make(): OrderScenario
    {
        $user = createUserWithPermissions(['read-own-orders', 'create-orders', 'update-orders', 'create-cart-items']);
        $user->update([
            'user_code' => '77528378',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
            'prices_lists' => [self::PRICE_LIST],
        ]);

        return new OrderScenario($user);
    }

    /**
     * Create a secondary branch of the user's Random entity.
     */
    public function makeSecondaryBranch(array $attributes = []): User
    {
        $branch = createUserWithPermissions(['read-own-orders', 'create-orders', 'create-cart-items']);
        $branch->update(array_merge([
            'user_code' => $this->user->user_code,
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
            'prices_lists' => $this->user->prices_lists,
        ], $attributes));

        return $branch;
    }

    /**
     * Add a new product to the authenticated user's cart, priced on the given list.
     */
    public function addProductToCart(
        float $price = 100,
        int $quantity = 2,
        string $unit = 'kg',
        string $priceList = self::PRICE_LIST,
    ): Product {
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
            'price_list_id' => $priceList,
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

        return $product;
    }
}
