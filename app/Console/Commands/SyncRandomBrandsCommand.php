<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomBrands;
use Illuminate\Console\Command;

class SyncRandomBrandsCommand extends Command
{
    protected $signature = 'random:sync-brands';
    protected $description = 'Sincroniza marcas desde la API de Random';

    public function handle()
    {
        $this->info('Iniciando sincronización de marcas...');
        
        SyncRandomBrands::dispatch()
            ->onQueue('random-brands');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
}
