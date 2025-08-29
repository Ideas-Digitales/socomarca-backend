<?php

namespace App\Jobs;

use App\Models\Price;
use App\Models\Product;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRandomPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomPrices started');
        try {
            $prices = $randomApi->getPricesLists();
            
            foreach($prices['datos'] as $price) {

                $pricipal_unit = $price['venderen']; // Unidad principal publicada; 1=primera, 2=segunda, 0=ambas

                foreach($price['unidades'] as $index => $unit) {
                    // 1=primera,
                    if ($pricipal_unit == 1 && $index != 0) {
                        continue; 
                    }
                    // 2=segunda
                    if ($pricipal_unit == 2 && $index != 1) {
                        continue;
                    }
                    
                    // 0=ambas
                    
                    $product = Product::where('random_product_id', $price['kopr'])->first();
                    
                    // Skip if product doesn't exist
                    if (!$product) {
                        Log::warning("Product with random_product_id {$price['kopr']} not found, skipping price sync");
                        continue;
                    }
    
                    $data = [
                        'product_id' => $product->id,
                        'random_product_id' => $price['kopr'],
                        'price_list_id' => $prices['nombre'],
                        'unit' => $unit['nombre'],
                        'price' => $unit['prunneto'][0]['f'],
                        'valid_from' => null,
                        'valid_to' => null,
                        'is_active' => true,
                    ];
                    
                    Price::updateOrCreate([
                        'random_product_id' => $price['kopr'],
                        'unit' => $unit['nombre']
                    ], $data);
                }
            }
            
            Log::info('SyncRandomPrices finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando precios: ' . $e->getMessage());
            throw $e;
        }
    }
} 