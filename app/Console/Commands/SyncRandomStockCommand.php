<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomStock;
use Illuminate\Console\Command;

class SyncRandomStockCommand extends Command
{
    protected $signature = 'random:sync-stock';
    protected $description = 'Sincroniza stock desde la API de Random';

    public function handle()
    {
        $this->info('Iniciando sincronización de stock...');
        
        SyncRandomStock::dispatch()
            ->onQueue('random-sync-products');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
} 