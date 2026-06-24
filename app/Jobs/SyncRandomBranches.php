<?php

namespace App\Jobs;

use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncRandomBranches implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(RandomApiService $randomApi): void
    {
        Log::info('SyncRandomBranches started');

        /** @var array $randomBranches */
        $randomBranches = $randomApi->fetchAndUpdateUsers();

        foreach ($randomBranches as $randomBranch) {
            try {
                if ($randomBranch['TIPOSUC'] == 'S') { //Sincroniza solo si es sucursal principal
                    $u = DB::table('users')
                        ->where('user_code', $randomBranch['KOEN'])
                        ->first(['id']);

                    if ($u == null) {
                        continue;
                    }

                    DB::table('branches')->upsert(
                        [
                            [
                                'code' => $randomBranch['SUEN'],
                                'user_code' => $randomBranch['KOEN']
                            ],
                            [
                                'code' => $randomBranch['SUEN'],
                                'user_code' => $randomBranch['KOEN'],
                                'name' => $randomBranch['NOKOEN'] ?? '',
                                'email' => $randomBranch['EMAIL'] ?? '',
                                'commercial_email' => $randomBranch['EMAILCOMER'] ?? '',
                                'phone' => $randomBranch['FOEN'] ?? '',
                                'rut' => $randomBranch['RTEN'],
                                'business_name' => $randomBranch['SIEN'] ?? '',
                                'user_id' => $u->id,
                            ],
                        ],
                        uniqueBy: ['code', 'user_code'],
                        update: [
                            'name',
                            'code',
                            'user_code',
                            'email',
                            'commercial_email',
                            'phone',
                            'rut',
                            'business_name',
                            'user_id',
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::critical('SyncRandomBranches failed: ' . $e->getMessage());
                return;
            }
        }
        Log::info('SyncRandomBranches completed successfully');
    }
}
