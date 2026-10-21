<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomContacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncRandomContactsCommand extends Command
{
    protected $signature = 'random:sync-contacts';

    protected $description = 'Sincroniza contactos desde la API de Random';

    public function handle(): void
    {
        $this->info('Iniciando sincronización de contactos...');

        Log::info('SyncRandomContacts started');

        SyncRandomContacts::dispatch()
            ->onQueue('random-sync-users');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
}
