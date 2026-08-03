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
     * Scope the query to the prices a user is allowed to see.
     *
     * Always requires active prices with stock. Prices are further limited to the user's
     * own price lists unless the user holds 'read-all-products', in which case every
     * price list is visible. A guest is always restricted (matching no price list).
     *
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

        $query->where('is_active', true)
            ->where('stock', '>', 0);

        if ($user === null || $user->restrictedToOwnPriceLists()) {
            $query->whereIn('price_list_id', $user?->prices_lists ?? []);
        }
    }
}
