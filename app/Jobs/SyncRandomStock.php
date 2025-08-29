<?php

namespace App\Jobs;

use App\Models\Price;
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

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomStock started');
        try {
            $stocks = $randomApi->getStock();
            $prices = Price::all();

            foreach($stocks['data'] as $stock) {
                $price = $prices->where('random_product_id', $stock['KOPR'])->first();
                if($price) {
                    $price->stock = $stock['STVEN1'];
                    $price->save();
                }
            }
            
            Log::info('SyncRandomStock finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando stock: ' . $e->getMessage());
            throw $e;
        }
    }
} 