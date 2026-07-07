<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItems\AddOrderToCartRequest;
use App\Http\Resources\CartItems\CartItemCollection;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Muestra los ítems del carrito de un usuario
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
                'added_items' => $addedItems,
                'updated_items' => $updatedItems,
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
