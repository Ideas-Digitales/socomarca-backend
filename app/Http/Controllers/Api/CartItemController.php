<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItems\DestroyRequest;
use App\Http\Requests\CartItems\StoreRequest;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class CartItemController extends Controller
{
    /**
     * Agrega un ítem al carrito del usuario
     * Si ya existe un ítem del producto, solamente se actualiza (incrementa) la cantidad
     * @param StoreRequest $storeRequest
     *
     * @return \Illuminate\Http\Response
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
                'price' => (int)$price,

            ],
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'total' => (int)($price * $item->quantity),
        ], 201);
    }

    /**
     * Elimina la cantidad especificada del ítem de
     * un producto en el carrito
     *
     * @param DestroyRequest $request
     *
     * @return array
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

    public function emptyCart(Request $request)
    {
        $user = $request->user();

        $user->cartItems()->delete();

        return response()->json(['message' => 'The cart has been emptied'], 200);
    }
}
