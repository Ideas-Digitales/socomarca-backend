<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomBrands;
use App\Jobs\SyncRandomCategories;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomStock;
use App\Jobs\SyncRandomUsers;
use App\Jobs\SyncRandomWarehouses;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SyncAllRandomDataCommand extends Command
{
    use Queueable;

    protected $signature = 'random:sync-all';
    protected $description = 'Sincroniza todos los datos desde la API de Random en cadena';

    public function handle()
    {
        $this->info('Iniciando sincronización completa de datos...');
        
        try {
            Bus::chain([
                new SyncRandomWarehouses(),    // Primero sincronizar bodegas
                new SyncRandomCategories(),
                new SyncRandomBrands(),
                new SyncRandomProducts(),
                new SyncRandomPrices(),
                new SyncRandomStock(),         // Stock ahora usa las bodegas sincronizadas
                new SyncRandomUsers(),
            ])->dispatch();

            $this->info('Proceso de sincronización encolado correctamente.');
            Log::info('Sincronización completa encolada');
        } catch (\Exception $e) {
            $this->error('Error al encolar la sincronización: ' . $e->getMessage());
            Log::error('Error al encolar sincronización completa: ' . $e->getMessage());
        }
    }
} 