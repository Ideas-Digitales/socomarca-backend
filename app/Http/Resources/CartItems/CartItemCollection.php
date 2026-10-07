<?php

namespace App\Http\Resources\CartItems;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CartItemCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        
        $items = $this->collection->map(function ($item) use ($request) {
            return (new CartItemResource($item))->toArray($request);
        })->values();
        
        $total = collect($items)
        ->filter(fn($item) => array_key_exists('subtotal', $item))
        ->sum('subtotal');

        return [
            /** @var list<CartItemResource> */
            'items' => $items,
            /**
             * Sum of the items' subtotals.
             *
             * @var float
             */
            'total' => $total,
        ];

        
    }
}
