<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Price;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolution of the price charged to a customer for each cart item.
 *
 * Random prices the sales document with the price lists of the entity it is issued
 * for, so the order must be priced with the lists of the customer (the branch the
 * order is placed for), not with those of the user who places it.
 *
 * When a product has a price in more than one list of the customer, the first list
 * in the order of users.prices_lists (KOLTVEN) wins.
 */
class CustomerPriceResolver
{
    /**
     * Resolve the active price of every item, in the customer's price lists.
     *
     * @param  User  $customer  User the order is placed for
     * @param  Collection<int, CartItem>  $items  Cart items (product and unit)
     * @return Collection<int, Price|null> Price by cart item ID; null when the customer has no price for the item
     */
    public function resolve(User $customer, Collection $items): Collection
    {
        $priceLists = array_values($customer->prices_lists ?? []);

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
