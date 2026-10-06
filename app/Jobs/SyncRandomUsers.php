<?php

namespace App\Jobs;

use App\Enums\BranchType;
use App\Events\EntityEmailIssuesDetected;
use App\Models\Address;
use App\Models\Municipality;
use App\Models\Region;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Syncs every Random customer entity (entity + branch record, primary or secondary) as a User.
 */
class SyncRandomUsers implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PAGE_SIZE = 100;

    /**
     * Random entity types (TIEN) synced as customers: C=Cliente, A=Ambos.
     */
    private const CUSTOMER_TYPES = ['C', 'A'];

    /**
     * Seconds the unique lock is kept, so a crashed worker does not block future syncs.
     */
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(RandomApiService $randomApi): void
    {
        Log::info('SyncRandomUsers started');

        $page = 1;

        do {
            $entities = $randomApi->getEntidadesUsuarios(self::PAGE_SIZE, $page);

            if (!is_array($entities) || !array_is_list($entities)) {
                Log::error('SyncRandomUsers failed: invalid Random entities response', [
                    'page' => $page,
                    'response' => $entities,
                ]);
                return;
            }

            Log::debug('SyncRandomUsers fetched Random entities', [
                'page' => $page,
                'count' => count($entities),
            ]);

            foreach ($entities as $entity) {
                try {
                    DB::transaction(fn () => $this->syncEntity($entity));
                } catch (\Throwable $e) {
                    Log::error('SyncRandomUsers failed syncing entity: ' . $e->getMessage(), [
                        'entity' => $entity,
                    ]);
                }
            }

            $page++;
        } while (!empty($entities));

        $this->reportEmailIssues();

        Log::info('SyncRandomUsers completed');
    }

    /**
     * Dispatch EntityEmailIssuesDetected when active synced users share a commercial email
     * or do not have one, so that the client can fix them in Random. Inactive users are
     * ignored because they cannot log in and may no longer exist in Random.
     */
    private function reportEmailIssues(): void
    {
        $columns = [
            'random_entity_id as IDMAEEN',
            'user_code as KOEN',
            'branch_code as SUEN',
            'name as NOKOEN',
            'email as EMAILCOMER',
        ];

        $duplicatedEmails = DB::table('users')
            ->selectRaw('lower(trim(email))')
            ->whereNotNull('random_entity_id')
            ->where('is_active', true)
            ->whereRaw("trim(coalesce(email, '')) <> ''")
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1');

        $duplicated = DB::table('users')
            ->whereNotNull('random_entity_id')
            ->where('is_active', true)
            ->whereIn(DB::raw('lower(trim(email))'), $duplicatedEmails)
            ->orderByRaw('lower(trim(email))')
            ->orderBy('random_entity_id')
            ->get($columns)
            ->map(fn (object $user) => (array) $user)
            ->all();

        $missing = DB::table('users')
            ->whereNotNull('random_entity_id')
            ->where('is_active', true)
            ->whereRaw("trim(coalesce(email, '')) = ''")
            ->orderBy('random_entity_id')
            ->get($columns)
            ->map(fn (object $user) => (array) $user)
            ->all();

        if (empty($duplicated) && empty($missing)) {
            return;
        }

        EntityEmailIssuesDetected::dispatch($duplicated, $missing);
    }

    /**
     * Create or update the user of a Random entity record.
     *
     * @param array<string, mixed> $entity
     */
    private function syncEntity(array $entity): void
    {
        $entityId = $entity['IDMAEEN'] ?? null;
        $userCode = trim((string) ($entity['KOEN'] ?? ''));

        if (empty($entityId) || $userCode === '') {
            Log::warning('SyncRandomUsers skipped entity: missing IDMAEEN or KOEN', ['entity' => $entity]);
            return;
        }

        if (!in_array($entity['TIEN'] ?? null, self::CUSTOMER_TYPES, true)) {
            return;
        }

        $entityId = (int) $entityId;
        $branchCode = trim((string) ($entity['SUEN'] ?? ''));
        $branchType = in_array($entity['TIPOSUC'] ?? null, BranchType::values(), true)
            ? $entity['TIPOSUC']
            : null;

        $this->associateExistingUser($entityId, $userCode, $branchCode);

        // upsert() bypasses the model mutators and casts, so values are normalized and encoded here.
        User::upsert(
            [
                [
                    'random_entity_id' => $entityId,
                    'user_code'        => $userCode,
                    'branch_code'      => $branchCode,
                    'branch_type'      => $branchType,
                    'rut'              => trim((string) ($entity['RTEN'] ?? '')),
                    'name'             => trim((string) ($entity['NOKOEN'] ?? '')),
                    'email'            => User::normalizeEmail($entity['EMAILCOMER'] ?? null),
                    'billing_email'    => User::normalizeEmail($entity['EMAIL'] ?? null),
                    'business_name'    => trim((string) ($entity['SIEN'] ?? '')),
                    'phone'            => trim((string) ($entity['FOEN'] ?? '')) ?: null,
                    'random_user_type' => $entity['TIEN'],
                    'prices_lists'     => json_encode($entity['KOLTVEN'] ?? []),
                    'random_synced_at' => now(),
                    'is_active'        => true,
                    'password'         => null,
                ],
            ],
            uniqueBy: ['random_entity_id'],
            update: [
                'user_code',
                'branch_code',
                'branch_type',
                'rut',
                'name',
                'email',
                'billing_email',
                'business_name',
                'phone',
                'random_user_type',
                'prices_lists',
                'random_synced_at',
            ]
        );

        $user = User::where('random_entity_id', $entityId)->firstOrFail();

        if (!$user->hasRole('customer')) {
            $user->assignRole('customer');
        }

        $this->syncAddress($user, $entity);
    }

    /**
     * Link a user that has not been synced by IDMAEEN yet to its Random entity, matching
     * by KOEN + SUEN, so that it keeps its orders, addresses, cart, favorites and password.
     */
    private function associateExistingUser(int $entityId, string $userCode, string $branchCode): void
    {
        if (User::where('random_entity_id', $entityId)->exists()) {
            return;
        }

        $user = User::whereNull('random_entity_id')
            ->whereRaw('trim(user_code) = ?', [$userCode])
            ->whereRaw("coalesce(trim(branch_code), '') = ?", [$branchCode])
            ->orderBy('id')
            ->first();

        if ($user === null) {
            return;
        }

        $user->forceFill(['random_entity_id' => $entityId])->save();

        Log::info('SyncRandomUsers associated existing user with Random entity', [
            'user_id' => $user->id,
            'random_entity_id' => $entityId,
            'koen' => $userCode,
            'suen' => $branchCode,
        ]);
    }

    /**
     * Create or update the Random-sourced address of a user.
     *
     * Random PAEN is ignored (always Chile). Geography is resolved as:
     * - Region.random_key = CL/{CIEN}
     * - Municipality.random_key = CL/{CIEN}/{CMEN}
     *
     * @param array{CIEN?: string, CMEN?: string, DIEN?: string, CPOSTAL?: string, FOEN?: string, NOKOEN?: string, KOEN?: string, SUEN?: string} $entity
     */
    private function syncAddress(User $user, array $entity): void
    {
        $cien = trim((string) ($entity['CIEN'] ?? ''));
        $cmen = trim((string) ($entity['CMEN'] ?? ''));
        $dien = trim((string) ($entity['DIEN'] ?? ''));
        $context = [
            'user_id' => $user->id,
            'koen' => $entity['KOEN'] ?? null,
            'suen' => $entity['SUEN'] ?? null,
        ];

        if ($cien === '' || $cmen === '') {
            Log::warning('SyncRandomUsers skipped address: missing CIEN/CMEN', $context + [
                'cien' => $cien,
                'cmen' => $cmen,
            ]);
            return;
        }

        if ($dien === '') {
            Log::warning('SyncRandomUsers skipped address: missing DIEN', $context);
            return;
        }

        // PAEN from Random is wrong (e.g. "CHI"); always use CL.
        $regionKey = "CL/{$cien}";
        $municipalityKey = "CL/{$cien}/{$cmen}";

        $region = Region::where('random_key', $regionKey)->first();
        if ($region === null) {
            Log::warning('SyncRandomUsers skipped address: region not found', $context + [
                'random_key' => $regionKey,
            ]);
            return;
        }

        $municipality = Municipality::where('random_key', $municipalityKey)->first();
        if ($municipality === null) {
            Log::warning('SyncRandomUsers skipped address: municipality not found', $context + [
                'random_key' => $municipalityKey,
            ]);
            return;
        }

        Address::updateOrCreate(
            ['user_id' => $user->id, 'is_synced' => true],
            [
                'region_id' => $region->id,
                'municipality_id' => $municipality->id,
                'address_line1' => $dien,
                'postal_code' => trim((string) ($entity['CPOSTAL'] ?? '')) ?: null,
                'phone' => trim((string) ($entity['FOEN'] ?? '')) ?: null,
                'contact_name' => $entity['NOKOEN'] ?? null,
                'type' => 'shipping',
                'is_default' => false,
            ]
        );
    }
}
