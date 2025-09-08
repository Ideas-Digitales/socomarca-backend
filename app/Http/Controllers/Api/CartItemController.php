<?php

namespace App\Http\Controllers\Api;

use App\Events\CartItemRemoved;
use App\Http\Controllers\Controller;
use App\Http\Requests\CartItems\DestroyRequest;
use App\Http\Requests\CartItems\StoreRequest;
use App\Models\CartItem;
use App\Models\ProductStock;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


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
        
        return DB::transaction(function () use ($data) {
            // Buscar item existente en el carrito
            $existingItem = CartItem::where('user_id', Auth::user()->id)
                ->where('product_id', $data['product_id'])
                ->where('unit', $data['unit'])
                ->first();

            $requestedQuantity = $data['quantity'];
            $currentQuantity = $existingItem ? $existingItem->quantity : 0;
            $totalQuantity = $currentQuantity + $requestedQuantity;

            // Buscar bodega con stock disponible por prioridad
            $warehouse = $this->findWarehouseWithStock($data['product_id'], $data['unit'], $requestedQuantity);
            
            if (!$warehouse) {
                return response()->json([
                    'message' => 'Stock insuficiente para este producto',
                    'available_stock' => $this->getTotalAvailableStock($data['product_id'], $data['unit'])
                ], Response::HTTP_BAD_REQUEST);
            }

            // Reservar stock
            $productStock = ProductStock::where('product_id', $data['product_id'])
                ->where('warehouse_id', $warehouse->id)
                ->where('unit', $data['unit'])
                ->first();

            if (!$productStock->reserveStock($requestedQuantity)) {
                return response()->json([
                    'message' => 'No se pudo reservar el stock solicitado',
                    'available_stock' => $productStock->available_stock
                ], Response::HTTP_BAD_REQUEST);
            }

            // Crear o actualizar item del carrito
            if ($existingItem) {
                // Si hay reserva previa, liberarla primero
                if ($existingItem->warehouse_id) {
                    $previousStock = ProductStock::where('product_id', $data['product_id'])
                        ->where('warehouse_id', $existingItem->warehouse_id)
                        ->where('unit', $data['unit'])
                        ->first();
                    if ($previousStock) {
                        $previousStock->releaseStock($existingItem->quantity);
                    }
                }
                
                $existingItem->quantity = $totalQuantity;
                $existingItem->warehouse_id = $warehouse->id;
                $existingItem->reserved_at = now();
                $existingItem->save();
                $item = $existingItem;
            } else {
                $item = CartItem::create([
                    'user_id' => Auth::user()->id,
                    'product_id' => $data['product_id'],
                    'quantity' => $requestedQuantity,
                    'unit' => $data['unit'],
                    'warehouse_id' => $warehouse->id,
                    'reserved_at' => now()
                ]);
            }

            // Cargar relaciones
            $item->load(['product', 'warehouse']);

            $price = $item->product->prices()
                ->where('unit', $item->unit)
                ->value('price');

            return response()->json([
                'message' => 'Producto agregado al carrito y stock reservado',
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'price' => (int)$price,
                ],
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'warehouse' => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'code' => $warehouse->warehouse_code,
                ],
                'total' => (int)($price * $item->quantity),
                'reserved_at' => $item->reserved_at->toDateTimeString(),
            ], 201);
        });
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

        return DB::transaction(function () use ($data) {
            $item = CartItem::where('user_id', Auth::user()->id)
                ->where('product_id', $data['product_id'])
                ->where('unit', $data['unit'])
                ->first();

            if (!$item) {
                return response()->json([
                    'message' => 'Product item not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $quantityToRemove = $data['quantity'];
            $shouldDeleteItem = ($item->quantity - $quantityToRemove) <= 0;
            
            // Liberar stock reservado proporcionalmente
            if ($item->warehouse_id) {
                $productStock = ProductStock::where('product_id', $data['product_id'])
                    ->where('warehouse_id', $item->warehouse_id)
                    ->where('unit', $data['unit'])
                    ->first();
                
                if ($productStock) {
                    $stockToRelease = $shouldDeleteItem ? $item->quantity : $quantityToRemove;
                    $productStock->releaseStock($stockToRelease);
                }
            }

            if ($shouldDeleteItem) {
                // Disparar evento antes de eliminar para el listener
                event(new CartItemRemoved($item));
                $item->delete();
            } else {
                $item->quantity = $item->quantity - $quantityToRemove;
                $item->save();
            }

            return response()->json([
                'message' => 'Product item quantity has been removed from cart',
                'action' => $shouldDeleteItem ? 'deleted' : 'updated',
                'remaining_quantity' => $shouldDeleteItem ? 0 : $item->quantity
            ]);
        });
    }

    public function emptyCart(Request $request)
    {
        $user = $request->user();

        return DB::transaction(function () use ($user) {
            $cartItems = $user->cartItems()->get();
            
            // Liberar stock reservado de todos los items
            foreach ($cartItems as $item) {
                if ($item->warehouse_id) {
                    $productStock = ProductStock::where('product_id', $item->product_id)
                        ->where('warehouse_id', $item->warehouse_id)
                        ->where('unit', $item->unit)
                        ->first();
                    
                    if ($productStock) {
                        $productStock->releaseStock($item->quantity);
                    }
                }
                
                // Disparar evento para cada item eliminado
                event(new CartItemRemoved($item));
            }

            // Eliminar todos los items del carrito
            $user->cartItems()->delete();

            return response()->json([
                'message' => 'The cart has been emptied and all stock reservations released',
                'released_items_count' => $cartItems->count()
            ], 200);
        });
    }

    /**
     * Buscar bodega con stock disponible por orden de prioridad
     */
    private function findWarehouseWithStock($productId, $unit, $quantity)
    {
        $warehouses = Warehouse::active()->byPriority()->get();
        
        foreach ($warehouses as $warehouse) {
            $productStock = ProductStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouse->id)
                ->where('unit', $unit)
                ->first();
            
            if ($productStock && $productStock->available_stock >= $quantity) {
                return $warehouse;
            }
        }
        
        return null;
    }

    /**
     * Obtener stock total disponible de un producto
     */
    private function getTotalAvailableStock($productId, $unit)
    {
        return ProductStock::where('product_id', $productId)
            ->where('unit', $unit)
            ->sum(DB::raw('stock - reserved_stock'));
    }
}
