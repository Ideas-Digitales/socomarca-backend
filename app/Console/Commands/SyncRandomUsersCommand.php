<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomUsers;
use App\Services\RandomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncRandomUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:sync-users';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza usuarios desde la API de Random';

    /**
     * Execute the console command.
     */
    public function handle(RandomApiService $randomApi)
    {
        $this->info('Iniciando sincronización de usuarios...');

         Log::info('SyncRandomUsers started');
       
        SyncRandomUsers::dispatch()
            ->onQueue('random-sync-users');

        $this->info('Proceso de sincronización encolado correctamente.');
    }
}
