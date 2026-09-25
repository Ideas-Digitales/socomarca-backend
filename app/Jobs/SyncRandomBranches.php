<?php

namespace App\Jobs;

use App\Enums\BranchType;
use App\Models\Address;
use App\Models\Municipality;
use App\Models\Region;
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
                            'user_code' => $randomBranch['KOEN'],
                            'name' => $randomBranch['NOKOEN'] ?? '',
                            'email' => $randomBranch['EMAIL'] ?? '',
                            'commercial_email' => $randomBranch['EMAILCOMER'] ?? '',
                            'phone' => $randomBranch['FOEN'] ?? '',
                            'rut' => $randomBranch['RTEN'],
                            'business_name' => $randomBranch['SIEN'] ?? '',
                            'user_id' => $u->id,
                            'branch_type' => $randomBranch['TIPOSUC'],
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
                        'branch_type',
                    ]
                );

                $branch = DB::table('branches')
                    ->where('code', $randomBranch['SUEN'])
                    ->where('user_code', $randomBranch['KOEN'])
                    ->first(['id', 'user_id']);

                if ($branch !== null) {
                    $this->syncAddress($branch->id, $branch->user_id, $randomBranch);
                }
            } catch (\Throwable $e) {
                Log::critical('SyncRandomBranches failed: ' . $e->getMessage());
                return;
            }
        }
        Log::info('SyncRandomBranches completed successfully');
    }

    /**
     * Create or update the Random-sourced address for a branch.
     *
     * Random PAEN is ignored (always Chile). Geography is resolved as:
     * - Region.random_key = CL/{CIEN}
     * - Municipality.random_key = CL/{CIEN}/{CMEN}
     *
     * @param  array{CIEN?: string, CMEN?: string, DIEN?: string, CPOSTAL?: string, FOEN?: string, NOKOEN?: string, TIPOSUC?: string, KOEN?: string, SUEN?: string}  $randomBranch
     */
    private function syncAddress(int $branchId, int $userId, array $randomBranch): void
    {
        $cien = trim((string) ($randomBranch['CIEN'] ?? ''));
        $cmen = trim((string) ($randomBranch['CMEN'] ?? ''));
        $dien = trim((string) ($randomBranch['DIEN'] ?? ''));

        if ($cien === '' || $cmen === '') {
            Log::warning('SyncRandomBranches skipped address: missing CIEN/CMEN', [
                'branch_id' => $branchId,
                'koen' => $randomBranch['KOEN'] ?? null,
                'suen' => $randomBranch['SUEN'] ?? null,
                'cien' => $cien,
                'cmen' => $cmen,
            ]);
            return;
        }

        if ($dien === '') {
            Log::warning('SyncRandomBranches skipped address: missing DIEN', [
                'branch_id' => $branchId,
                'koen' => $randomBranch['KOEN'] ?? null,
                'suen' => $randomBranch['SUEN'] ?? null,
            ]);
            return;
        }

        // PAEN from Random is wrong (e.g. "CHI"); always use CL.
        $regionKey = "CL/{$cien}";
        $municipalityKey = "CL/{$cien}/{$cmen}";

        $region = Region::where('random_key', $regionKey)->first();
        if ($region === null) {
            Log::warning('SyncRandomBranches skipped address: region not found', [
                'branch_id' => $branchId,
                'random_key' => $regionKey,
                'koen' => $randomBranch['KOEN'] ?? null,
                'suen' => $randomBranch['SUEN'] ?? null,
            ]);
            return;
        }

        $municipality = Municipality::where('random_key', $municipalityKey)->first();
        if ($municipality === null) {
            Log::warning('SyncRandomBranches skipped address: municipality not found', [
                'branch_id' => $branchId,
                'random_key' => $municipalityKey,
                'koen' => $randomBranch['KOEN'] ?? null,
                'suen' => $randomBranch['SUEN'] ?? null,
            ]);
            return;
        }

        $type = ($randomBranch['TIPOSUC'] ?? null) === BranchType::PRIMARY
            ? 'billing'
            : 'shipping';

        Address::updateOrCreate(
            ['branch_id' => $branchId],
            [
                'user_id' => $userId,
                'region_id' => $region->id,
                'municipality_id' => $municipality->id,
                'address_line1' => $dien,
                'postal_code' => $randomBranch['CPOSTAL'] ?: null,
                'phone' => $randomBranch['FOEN'] ?: null,
                'contact_name' => $randomBranch['NOKOEN'] ?? null,
                'type' => $type,
                'is_default' => false,
            ]
        );
    }
}
