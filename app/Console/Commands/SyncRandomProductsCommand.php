<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomProducts;
use Illuminate\Console\Command;

class SyncRandomProductsCommand extends Command
{
    protected $signature = 'random:sync-products';
    protected $description = 'Sincroniza productos desde la API de Random';

    public function handle()
    {
        $this->info('Iniciando sincronización de productos...');
        
        SyncRandomProducts::dispatch()
            ->onQueue('random-sync-products');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
} 