<?php

namespace App\Console\Commands;

use App\Jobs\CheckBlockedCreditLinesJob;
use Illuminate\Console\Command;

class SyncRandomCredit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:sync-credit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs random users credits';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queueing Random credit synchronization');

        CheckBlockedCreditLinesJob::dispatch()
            ->onQueue('random-sync-credit');

        $this->info('Random credit synchronization has been queued');
    }
}
