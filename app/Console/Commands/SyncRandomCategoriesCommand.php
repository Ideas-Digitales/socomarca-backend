<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomCategories;
use Illuminate\Console\Command;

class SyncRandomCategoriesCommand extends Command
{
    protected $signature = 'random:sync-categories';
    protected $description = 'Sincroniza categorías desde la API de Random';

    public function handle()
    {
        $this->info('Iniciando sincronización de categorías...');
        
        SyncRandomCategories::dispatch()
            ->onQueue('random-sync-products');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
} 