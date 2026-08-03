<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    /**
     * Permissions granting product read access.
     *
     * 'read-all-products' sees every product; 'read-price-list-products' sees only the
     * products priced in the user's own price lists.
     *
     * @var array<string>
     */
    private const READ_PERMISSIONS = [
        'read-all-products',
        'read-price-list-products',
    ];

    /**
     * Determine whether the user can view any models.
     *
     * Either read permission grants access; how much the user actually sees is decided
     * later by the price-list filtering.
     *
     * @param User $user The authenticated user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->canAny(self::READ_PERMISSIONS);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The authenticated user
     * @param Product $product The product being accessed
     * @return bool
     */
    public function view(User $user, Product $product): bool
    {
        return $user->canAny(self::READ_PERMISSIONS);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
