<?php

namespace App\Models;

use App\Services\CategoryAssociationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'random_product_id',
        'name',
        'description',
        'supercategory_id',
        'category_id',
        'subcategory_id',
        'brand_id',
        'sku',
        'status',
        'price_id',
    ];

    public function supercategory()
    {
        return $this->belongsTo(Category::class, 'supercategory_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function prices()
    {
        return $this->hasMany(Price::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function userFavorites($userId)
    {
        return $this->hasMany(Favorite::class)
            ->whereHas('favoriteList', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    public function scopeActive($query)
    {
        $query->where('status', '=', true);

        return $query;
    }

    /**
     * Scope the query to the products whose category chain does not contradict itself.
     *
     * Random ERP resolves the superfamily (FMPR) and the family (PFPR) of a product
     * independently, so a product can declare a supercategory that is not the parent of
     * its category. Such a product must not hold up a node of the category tree nor show
     * up in the category facets of the search, because neither offers a navigable path
     * to it.
     *
     * A product filed under a single level is legitimate and stays: the rule only fires
     * when both ends exist and disagree, so a NULL is never inconsistent.
     *
     * Resolved in SQL, not over a fetched collection, because it is applied inside a
     * whereHas() of the category tree and inside the facet query builder.
     *
     * No-op while the "strict_category_association_enabled" flag is off, which is the
     * default: the whole point of the flag is to be switched on and off hot, without a
     * resync of the Random data.
     *
     * @see \App\Services\CategoryAssociationService
     * @see \App\Http\Controllers\Api\CategoryController::hasVisiblePrices()
     * @see \App\Services\Data\ProductQueryService::getMatchingCategories()
     * @param \Illuminate\Database\Eloquent\Builder $query The query builder to constrain
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConsistentlyFiled($query)
    {
        if (! app(CategoryAssociationService::class)->strictEnabled()) {
            return $query;
        }

        $query->where(fn ($chain) => self::whereChildBelongsTo(
            $chain,
            'supercategory_id',
            'category_id',
            'category_parent_check'
        ));

        $query->where(fn ($chain) => self::whereChildBelongsTo(
            $chain,
            'category_id',
            'subcategory_id',
            'subcategory_parent_check'
        ));

        return $query;
    }

    /**
     * One link of the chain: the category held in $childColumn must hang from the one
     * held in $parentColumn, unless either end is missing.
     *
     * The categories table is aliased because the callers may already be joining it
     * (the product listing sorts by category name).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query The nested boolean group to fill
     * @param string $parentColumn Column of products holding the parent category
     * @param string $childColumn Column of products holding the child category
     * @param string $alias Alias for the categories table inside the EXISTS subquery
     * @return void
     */
    private static function whereChildBelongsTo($query, string $parentColumn, string $childColumn, string $alias): void
    {
        $query->whereNull("products.{$parentColumn}")
            ->orWhereNull("products.{$childColumn}")
            ->orWhereExists(
                fn ($exists) => $exists->selectRaw('1')
                    ->from("categories as {$alias}")
                    ->whereColumn("{$alias}.id", "products.{$childColumn}")
                    ->whereColumn("{$alias}.parent_category_id", "products.{$parentColumn}")
            );
    }
}
