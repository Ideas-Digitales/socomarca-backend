<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Price extends Model
{#98757
    use HasFactory;

    protected $fillable = [
        'product_id',
        'random_product_id',
        'price_list_id',
        'unit',
        'price',
        'stock',
        'valid_from',
        'valid_to',
        'is_active',
        'unit',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return BelongsTo<Product>
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Apply the price visibility rules to a query builder or a join clause.
     *
     * Single definition of "which prices may this user see", shared by the relation-based
     * scope below and by the join in ProductQueryService, so the category tree and the
     * product listing can never disagree:
     *
     *  - the price must be active and in stock;
     *  - zero-price rows are excluded unless config('random.show_product_zero_price');
     *  - the price list must belong to the user, unless the user holds 'read-all-products'.
     *
     * A guest is always restricted and therefore matches no price list.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|\Illuminate\Database\Query\JoinClause $query The query or join clause to constrain
     * @param User|null $user The user to scope for; null (guest) matches no price list
     * @param string $table Column prefix, e.g. 'prices' when constraining a join
     * @return void
     */
    public static function applyVisibility($query, ?User $user, string $table = ''): void
    {
        $column = $table === '' ? '' : "{$table}.";

        $query->where("{$column}is_active", true)
            ->where("{$column}stock", '>', 0);

        if (! config('random.show_product_zero_price')) {
            $query->where("{$column}price", '>', 0);
        }

        if ($user === null || $user->restrictedToOwnPriceLists()) {
            $query->whereIn("{$column}price_list_id", $user?->prices_lists ?? []);
        }
    }

    /**
     * Scope the query to the prices a user is allowed to see.
     *
     * @see self::applyVisibility()
     * @param Builder $query The query builder to modify
     * @param User|null $user The user to scope for, defaults to the authenticated user
     * @return void
     */
    #[Scope]
    protected function visibleTo(Builder $query, ?User $user = null): void
    {
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable | \App\Models\User $user
         */
        $user ??= Auth::user();

        self::applyVisibility($query, $user);
    }
}
