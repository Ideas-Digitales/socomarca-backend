<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItems\DestroyRequest;
use App\Http\Requests\CartItems\StoreRequest;
use App\Models\CartItem;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


#[Group('Cart', weight: 10)]
class CartItemController extends Controller
{
    /**
     * Add an item to the cart
     *
     * Adds the quantity of a product unit to the authenticated user's cart. If the cart already has that
     * product with the same unit, its quantity is increased instead. The product must have an active price
     * for the unit.
     */
    public function store(StoreRequest $storeRequest)
    {
        $data = $storeRequest->validated();
        $item = CartItem::where('user_id', Auth::user()->id)
            ->where('product_id', $data['product_id'])
            ->where('unit', $data['unit'])
            ->first();

        if ($item) {
            $item->quantity = $item->quantity + $data['quantity'];
            $item->save();
        } else {
            $item = new CartItem;
            $item->user_id = Auth::user()->id;
            $item->product_id = $data['product_id'];
            $item->quantity = $data['quantity'];
            $item->unit = $data['unit'];
            $item->save();
        }

        // Cargar la relación del producto
        $item->load('product');

        $price = 0;
        if ($item->product) {
            $price = $item->product->prices()
                ->where('unit', $item->unit)
                ->where('is_active', true)
                ->whereIn('price_list_id', Auth::user()->prices_lists)
                ->value('price') ?? 0;
        }
        return response()->json([
            'product' => [
                'id' => $item->product->id,
                'name' => $item->product->name,
                /** Unit price from the user's price lists; 0 when the user has no active price for the unit. */
                'price' => (int)$price,

            ],
            /** Quantity of the item in the cart after adding. */
            'quantity' => $item->quantity,
            /** @var string */
            'unit' => $item->unit,
            /** Unit price × cart quantity. */
            'total' => (int)($price * $item->quantity),
        ], 201);
    }

    /**
     * Remove a quantity of an item from the cart
     *
     * Subtracts the quantity from the authenticated user's cart item for the product unit, and deletes the
     * item when its quantity reaches 0. Removing more than the item's quantity responds 422. If the cart has
     * no item for the product unit, it responds 200 with the message `Product item not found`.
     */
    public function destroy(DestroyRequest $request)
    {
        $data = $request->validated();

        $item = CartItem::where('user_id', Auth::user()->id)
            ->where('product_id', $data['product_id'])
            ->where('unit', $data['unit'])
            ->first();

        if (!$item) {
            return [
                'message' => 'Product item not found'
            ];
        }

        if (($item->quantity - $data['quantity']) == 0) {
            $item->delete();
        } else {
            $item->quantity = $item->quantity - $data['quantity'];
            $item->save();
        }

        return [
            'message' => 'Product item quantity has been removed from cart'
        ];
    }

    /**
     * Empty the cart
     *
     * Deletes every item of the authenticated user's cart.
     */
    public function emptyCart(Request $request)
    {
        $user = $request->user();

        $user->cartItems()->delete();

        return response()->json(['message' => 'The cart has been emptied'], 200);
    }
}
