<?php

namespace App\Console\Commands;

use App\Jobs\SyncRandomWarehouses;
use Illuminate\Console\Command;

class SyncRandomWarehousesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:sync-warehouses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize warehouses from Random ERP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting warehouse synchronization...');
        
        SyncRandomWarehouses::dispatch();
        
        $this->info('Warehouse synchronization job dispatched successfully.');
        
        return Command::SUCCESS;
    }
}