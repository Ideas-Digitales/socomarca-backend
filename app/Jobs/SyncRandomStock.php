<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRandomStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('random-stock');
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomStock started');
        try {
            $warehouses = Warehouse::active()->get();
            
            foreach ($warehouses as $warehouse) {
                $this->syncWarehouseStock($randomApi, $warehouse);
            }
            
            Log::info('SyncRandomStock finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando stock: ' . $e->getMessage());
            throw $e;
        }
    }

    private function syncWarehouseStock(RandomApiService $randomApi, Warehouse $warehouse)
    {
        Log::info("Syncing stock for warehouse: {$warehouse->warehouse_code}");
        
        try {
            $stocks = $randomApi->getStock(null, null, $warehouse->warehouse_code);
            
            if (!isset($stocks['data']) || !is_array($stocks['data'])) {
                Log::warning("No stock data for warehouse: {$warehouse->warehouse_code}");
                return;
            }

            foreach ($stocks['data'] as $stock) {
                $this->syncProductStock($stock, $warehouse);
            }
            
        } catch (\Exception $e) {
            Log::error("Error syncing stock for warehouse {$warehouse->warehouse_code}: " . $e->getMessage());
        }
    }

    private function syncProductStock(array $stockData, Warehouse $warehouse)
    {
        // Buscar producto por random_product_id
        $product = Product::where('random_product_id', $stockData['KOPR'])->first();
        
        if (!$product) {
            Log::warning("Product not found: {$stockData['KOPR']}");
            return;
        }

        // Obtener la unidad desde los precios del producto
        $price = $product->prices()->where('is_active', true)->first();
        if (!$price) {
            Log::warning("No active price found for product: {$product->id}");
            return;
        }

        // Crear o actualizar stock del producto en esta bodega
        ProductStock::updateOrCreate(
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'unit' => $price->unit
            ],
            [
                'stock' => $stockData['STVEN1'] ?? 0,
            ]
        );

        Log::debug("Stock updated - Product: {$product->id}, Warehouse: {$warehouse->warehouse_code}, Stock: {$stockData['STVEN1']}");
    }
}