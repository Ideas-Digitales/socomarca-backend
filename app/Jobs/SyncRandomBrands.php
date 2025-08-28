<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRandomBrands implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomBrands started');
        try {
            $brands = $randomApi->getBrands();
            
            foreach ($brands['data'] as $product) {

                Log::info('SyncRandomBrands: ' . json_encode($product));
                
                if (!empty($product['MRPR'])) {
                    $data = [
                        'random_erp_code' => $product['MRPR'],
                        'name' => !empty($product['NOKOMR']) ? $product['NOKOMR'] : $product['MRPR'],
                    ];
                    
                    Brand::updateOrCreate(
                        ['random_erp_code' => $product['MRPR']], 
                        $data
                    );
                }
            }
            
            Log::info('SyncRandomBrands finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando marcas: ' . $e->getMessage());
            throw $e;
        }
    }
}
