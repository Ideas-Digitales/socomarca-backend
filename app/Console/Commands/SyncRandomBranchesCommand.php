<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomBranches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncRandomBranchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:sync-branches';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs branches from Random ERP API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queuing branches sync job');

        Log::info('SyncRandomBranches started');

        SyncRandomBranches::dispatch()
            ->onQueue('random-branches');

        $this->info('Branches sync job has been queued successfully');
    }
}
