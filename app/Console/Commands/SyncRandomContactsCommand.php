<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncRandomContactsCommand extends Command
{
    protected $signature = 'random:sync-contacts';

    protected $description = 'La sincronización de contactos está desactivada';

    public function handle(): void
    {
        Log::info('SyncRandomContacts skipped: contact sync is disabled');

        $this->warn('La sincronización de contactos está desactivada.');
    }
}
