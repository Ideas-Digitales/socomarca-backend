<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItems\AddOrderToCartRequest;
use App\Http\Resources\CartItems\CartItemCollection;
use App\Models\CartItem;
use App\Models\Order;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Group('Cart', 'Shopping cart of the authenticated user, priced with the user\'s price lists.', weight: 10)]
class CartController extends Controller
{
    /**
     * Show the cart
     *
     * Lists the authenticated user's cart items with the active price of their unit in the user's price
     * lists, and the cart total. Items without such a price show a price and subtotal of 0.
     *
     * @return CartItemCollection
     */
    public function index()
    {
        return $this->getCartItemsCollection(Auth::user());
    }

    /**
     * Carga los ítems del carrito de un usuario con su producto y el precio
     * correspondiente a las listas de precio a las que tiene acceso.
     *
     * @return CartItemCollection
     */
    private function getCartItemsCollection($user): CartItemCollection
    {
        $items = CartItem::where('user_id', $user->id)
            ->orderBy('id', 'ASC')
            ->with([
                'product.category',
                'product.subcategory',
                'product.brand',
                'activePrices' => fn ($query) => $query->whereIn('price_list_id', $user->prices_lists),
            ])
            ->get();

        return new CartItemCollection($items);
    }

    /**
     * Add the items of an order to the cart
     *
     * Re-adds the products of a previous order to the authenticated user's cart, with the order's
     * quantities and units. Products already in the cart with the same unit get their quantity increased.
     * Prices are not taken from the order: the returned cart is priced with the current user's price lists.
     *
     * Allowed for orders the user placed or orders placed for the user (as customer); other orders respond 403.
     */
    #[Response(500, 'Unexpected error; no item is added to the cart')]
    public function addOrderToCart(AddOrderToCartRequest $request)
    {
        $validated = $request->validated();
        $order = Order::with('orderDetails')->find($validated['order_id']);

        DB::beginTransaction();

        try {
            $userId = Auth::id();
            $addedItems = 0;
            $updatedItems = 0;

            foreach ($order->orderDetails as $orderItem) {
                $existingCartItem = CartItem::where('user_id', $userId)
                    ->where('product_id', $orderItem->product_id)
                    ->where('unit', $orderItem->unit)
                    ->first();

                if ($existingCartItem) {
                    $existingCartItem->quantity += $orderItem->quantity;
                    $existingCartItem->save();
                    $updatedItems++;
                } else {
                    CartItem::create([
                        'user_id' => $userId,
                        'product_id' => $orderItem->product_id,
                        'quantity' => $orderItem->quantity,
                        'unit' => $orderItem->unit
                    ]);
                    $addedItems++;
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Productos de la orden agregados al carrito exitosamente',
                /** Number of order lines added to the cart as new items. */
                'added_items' => $addedItems,
                /** Number of order lines that increased the quantity of an existing cart item. */
                'updated_items' => $updatedItems,
                /** The updated cart. */
                'cart' => $this->getCartItemsCollection(Auth::user()),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al agregar productos al carrito',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
