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

uses(Tests\TestCase::class,
    \Illuminate\Foundation\Testing\DatabaseTruncation::class)->in('Feature');
//uses(Tests\TestCase::class)->in('Unit');

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
