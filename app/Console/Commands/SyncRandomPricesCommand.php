<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomPrices;
use Illuminate\Console\Command;

class SyncRandomPricesCommand extends Command
{
    protected $signature = 'random:sync-prices';
    protected $description = 'Sincroniza precios desde la API de Random';

    public function handle()
    {
        $this->info('Iniciando sincronización de precios...');
        
        SyncRandomPrices::dispatch()
            ->onQueue('random-sync-products');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
} 