<?php

namespace App\Jobs;

use App\Models\Warehouse;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRandomWarehouses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('random-warehouses');
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomWarehouses started');
        
        try {
            $warehouses = $randomApi->getWarehouses();
            
            if (!isset($warehouses['data']) || !is_array($warehouses['data'])) {
                Log::error('Invalid warehouses data structure received');
                return;
            }

            foreach ($warehouses['data'] as $warehouseData) {
                $this->syncWarehouse($warehouseData);
            }
            
            Log::info('SyncRandomWarehouses finished successfully');
        } catch (\Exception $e) {
            Log::error('Error sincronizando bodegas: ' . $e->getMessage());
            throw $e;
        }
    }

    private function syncWarehouse(array $data)
    {
        $warehouse = Warehouse::updateOrCreate(
            ['warehouse_code' => $data['KOBO']],
            [
                'business_code' => $data['EMPRESA'],
                'branch_code' => $data['KOSU'],
                'warehouse_code' => $data['KOBO'],
                'name' => $data['NOKOBO'],
                'address' => $data['DIBO'] ?? null,
                'phone' => $data['FOBO'] ?? null,
                'no_explosion' => $data['NOEXPLOSI'] ?? false,
                'no_lot' => $data['SINLOTE'] ?? true,
                'no_location' => $data['SINUBIC'] ?? true,
                'warehouse_type' => $data['TIPOBODE'] ?? null,
                'is_active' => true,
            ]
        );

        // Si no hay ninguna bodega por defecto, asignar prioridad 1 a la primera
        if (Warehouse::where('priority', 1)->count() === 0) {
            $warehouse->priority = 1;
            $warehouse->save();
        }

        Log::info("Warehouse synced: {$warehouse->warehouse_code} - {$warehouse->name}");
    }
}