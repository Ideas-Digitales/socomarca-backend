<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Price;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolution of the price of each cart item in the price lists of a user.
 *
 * Random prices the sales document with the price lists of the entity branch it is
 * issued for, which is the user who places the order (also when it orders for one of
 * its secondary branches). The cart and the order are priced the same way, so the
 * total shown in the cart is the one charged.
 *
 * When a product has a price in more than one list of the user, the first list in
 * the order of users.prices_lists (KOLTVEN) wins.
 */
class CartPriceResolver
{
    /**
     * Resolve the active price of every item, in the user's price lists.
     *
     * @param  User  $user  User whose price lists apply
     * @param  Collection<int, CartItem>  $items  Cart items (product and unit)
     * @return Collection<int, Price|null> Price by cart item ID; null when the user has no price for the item
     */
    public function resolve(User $user, Collection $items): Collection
    {
        $priceLists = array_values($user->prices_lists ?? []);

        $prices = $priceLists === [] || $items->isEmpty()
            ? collect()
            : Price::query()
                ->whereIn('product_id', $items->pluck('product_id')->unique())
                ->whereIn('price_list_id', $priceLists)
                ->where('is_active', true)
                ->get()
                ->sortBy(fn (Price $price) => array_search($price->price_list_id, $priceLists, true));

        return $items->mapWithKeys(fn (CartItem $item) => [
            $item->id => $prices->first(
                fn (Price $price) => (int) $price->product_id === (int) $item->product_id
                    && $price->unit === $item->unit
            ),
        ]);
    }
}
