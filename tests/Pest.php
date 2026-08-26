<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is \PHPUnit\Framework\TestCase. Of course, you may
| need to change it using the "uses()" function to bind a different classes such as
| \Illuminate\Foundation\Testing\TestCase to communicate with your Laravel application.
|
*/

use App\Models\Address;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\FavoriteList;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use Laragear\Rut\Facades\Generator as RutGenerator;

pest()->extend(\Tests\TestCase::class);

uses(
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions.
| Pest provides a beautiful API for doing this, however, you may prefer
| to use the traditional PHPUnit assertions sometimes.
|
| Here you can register any custom expectations you wish to use:
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to
| your project that you don't want to repeat in every file. Here you can also expose helpers
| to be used globally across all your tests.
|
*/

function createUser()
{
    return User::factory()->create();
}

function createUserHasAddress()
{
    return User::factory()
        ->has(Address::factory(), 'addresses')
        ->create();
}

function createPrice()
{
    return Price::factory()->create();
}

function createCategory()
{
    return Category::factory()
        ->has(Subcategory::factory(), 'subCategories')
        ->create();
}

function createBrand()
{
    return Brand::factory()->create();
}

function createProduct()
{
    return Product::factory()->create();
}

function createUserHasFavoriteList()
{
    return User::factory()
        ->has(FavoriteList::factory(), 'favoritesList')
        ->create();
}

function createBranch()
{
    return Branch::factory()->create();
}

function createUserHasFavorite()
{
    return User::factory()
        ->has(FavoriteList::factory()
            ->has(Favorite::factory(), 'favorites'), 'favoritesList')
        ->create();
}

function getPriceListCode(): string
{
    return "LIST1";
}

function generateUserData() {
    return [
        'name' => fake()->firstName() . ' ' . fake()->lastName(),
        'email' => fake()->email,
        'password' => fake()->password(10, 12),
        'phone' => strval(fake()->numberBetween(1000000000, 2000000000)),
        'rut' => RutGenerator::makeOne()->formatBasic(),
        'business_name' => fake()->company(),
        'is_active' => true,
    ];
}

function createUserWithPermissions(array $permissions): User {
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    return $user;
}

function createCustomerWithBranch(): array
{
    $user = User::factory()->create([
        "rut" => "12345678-9",
        "user_code" => "12345678-9",
        "branch_code" => "CM",
    ]);
    if (!$user->hasRole("customer")) {
        $user->assignRole("customer");
    }
    $branch = Branch::factory()->create([
        "user_id" => $user->id,
        "code" => "CM",
        "user_code" => "12345678-9",
    ]);

    return [$user, $branch];
}

/**
 * Crea un producto con su precio en la lista de pruebas.
 *
 * Ademas de los campos del producto (incluido brand_id), acepta
 * price_list_id, price, stock y price_is_active para el precio.
 */
function createProductWithPrice(array $attributes = []): Product
{
    $product = Product::create($attributes);
    Price::create([
        "product_id" => $product->id,
        "price_list_id" => $attributes["price_list_id"] ?? getPriceListCode(),
        "unit" => "un",
        "price" => $attributes["price"] ?? 5000,
        "stock" => $attributes["stock"] ?? 50,
        "is_active" => $attributes["price_is_active"] ?? true,
        "valid_from" => now()->subDays(10),
    ]);
    return $product;
}

/**
 * Create a user allowed to read brands and to see the test price list.
 */
function brandUser(): User
{
    $user = createUserWithPermissions(['read-all-brands']);
    $user->update(['prices_lists' => [getPriceListCode()]]);

    return $user;
}

/**
 * Create a brand with one product priced on the test price list.
 *
 * @param array $priceAttributes Overrides for the price row, e.g. stock or price
 * @param array $productAttributes Overrides for the product row, e.g. status
 */
function brandWithProduct(string $name, array $priceAttributes = [], array $productAttributes = []): Brand
{
    $brand = Brand::factory()->create(['name' => $name]);

    createProductWithPrice(array_merge([
        'name' => "Producto {$name}",
        'sku' => 'SKU-' . uniqid(),
        'brand_id' => $brand->id,
        'status' => true,
    ], $productAttributes, $priceAttributes));

    return $brand;
}
