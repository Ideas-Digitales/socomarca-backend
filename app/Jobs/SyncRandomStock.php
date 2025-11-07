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
            // Obtener todo el stock desde Random ERP (una sola llamada)
            $allStock = $randomApi->getStock(null, null, null, null, env('RANDOM_ERP_PRICES_MODALITY', 'ADMIN'));
            
            if (!isset($allStock['data']) || !is_array($allStock['data'])) {
                Log::warning('No stock data received from Random API');
                return;
            }

            Log::info('Received ' . count($allStock['data']) . ' stock records from Random API');

            // Resetear todo el stock a 0 antes de sincronizar
            ProductStock::query()->update(['stock' => 0]);
            Log::info('Reset all product stocks to 0');

            // Procesar cada registro de stock
            foreach ($allStock['data'] as $stockData) {
                $this->syncProductStock($stockData);
            }
            
            Log::info('SyncRandomStock finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando stock: ' . $e->getMessage());
            throw $e;
        }
    }


    private function syncProductStock(array $stockData)
    {
        // Buscar producto por random_product_id
        $product = Product::where('random_product_id', $stockData['KOPR'])->first();
        
        if (!$product) {
            Log::warning("Product not found: {$stockData['KOPR']}");
            return;
        }

        // Buscar bodega por warehouse_code del API (KOBO)
        $warehouse = Warehouse::where('warehouse_code', $stockData['KOBO'])->first();
        
        if (!$warehouse) {
            Log::warning("Warehouse not found: {$stockData['KOBO']}");
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

        Log::debug("Stock updated - Product: {$product->id} ({$stockData['KOPR']}), Warehouse: {$warehouse->warehouse_code}, Stock: {$stockData['STVEN1']}");
    }
}