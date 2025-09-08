<?php

namespace App\Listeners;

use App\Events\CartItemRemoved;
use App\Events\OrderCompleted;
use App\Events\OrderFailed;
use App\Models\ProductStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ReleaseReservedStock implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        if ($event instanceof OrderCompleted || $event instanceof OrderFailed) {
            $this->handleOrderEvent($event);
        } elseif ($event instanceof CartItemRemoved) {
            $this->handleCartItemRemoved($event);
        }
    }

    private function handleOrderEvent($event)
    {
        $order = $event->order;
        
        Log::info("Releasing reserved stock for order: {$order->id}");

        foreach ($order->orderItems as $item) {
            if ($item->warehouse_id) {
                $productStock = ProductStock::where('product_id', $item->product_id)
                    ->where('warehouse_id', $item->warehouse_id)
                    ->where('unit', $item->unit)
                    ->first();

                if ($productStock) {
                    if ($event instanceof OrderCompleted) {
                        // Para órdenes completadas, reducir stock real y liberar reserva
                        $productStock->reduceStock($item->quantity);
                        Log::info("Stock reduced for completed order - Product: {$item->product_id}, Quantity: {$item->quantity}");
                    } else {
                        // Para órdenes fallidas, solo liberar reserva
                        $productStock->releaseStock($item->quantity);
                        Log::info("Stock released for failed order - Product: {$item->product_id}, Quantity: {$item->quantity}");
                    }
                }
            }
        }
    }

    private function handleCartItemRemoved(CartItemRemoved $event)
    {
        $cartItem = $event->cartItem;
        
        if ($cartItem->warehouse_id) {
            Log::info("Releasing reserved stock for cart item: Product {$cartItem->product_id}");

            $productStock = ProductStock::where('product_id', $cartItem->product_id)
                ->where('warehouse_id', $cartItem->warehouse_id)
                ->where('unit', $cartItem->unit)
                ->first();

            if ($productStock) {
                $productStock->releaseStock($cartItem->quantity);
                Log::info("Stock released for removed cart item - Product: {$cartItem->product_id}, Quantity: {$cartItem->quantity}");
            }
        }
    }
}