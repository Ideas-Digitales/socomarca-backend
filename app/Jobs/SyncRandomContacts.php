<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\User;
use App\Services\RandomApiService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Copies each Random contact onto the branch user whose code equals `SUTRABCON`.
 *
 * A contact whose branch code SCR cannot resolve is left untouched and reported. Contacts that
 * Random no longer returns are deleted, except those skipped in the same run.
 */
class SyncRandomContacts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds the unique lock is kept, so a crashed worker does not block future syncs.
     */
    public int $uniqueFor = 3600;

    public function handle(RandomApiService $randomApi): void
    {
        Log::info('SyncRandomContacts started');

        $startedAt = now();
        $entityCodes = User::query()
            ->whereNotNull('user_code')
            ->pluck('user_code')
            ->map(fn ($code) => trim((string) $code))
            ->filter(fn (string $code) => $code !== '')
            ->unique()
            ->values();

        foreach ($entityCodes as $entityCode) {
            try {
                $this->syncEntity($randomApi, $entityCode, $startedAt);
            } catch (\Throwable $e) {
                Log::error('SyncRandomContacts failed syncing entity: '.$e->getMessage(), [
                    'entity_code' => $entityCode,
                ]);
            }
        }

        Log::info('SyncRandomContacts completed', [
            'entities' => $entityCodes->count(),
        ]);
    }

    private function syncEntity(RandomApiService $randomApi, string $entityCode, CarbonInterface $startedAt): void
    {
        $response = $randomApi->getContacts($entityCode);

        if (($response['object'] ?? null) !== 'list' || ! isset($response['data']) || ! is_array($response['data'])) {
            Log::error('SyncRandomContacts skipped entity: invalid Random contacts response', [
                'entity_code' => $entityCode,
                'response' => $response,
            ]);

            return;
        }

        $branches = User::query()
            ->where('user_code', $entityCode)
            ->get()
            ->groupBy(fn (User $user): string => trim((string) $user->branch_code));

        $skippedRuts = [];

        foreach ($response['data'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attributes = Contact::attributesFromRandom($row);
            if ($attributes === null || $attributes['entity_code'] !== $entityCode) {
                Log::warning('SyncRandomContacts skipped contact', [
                    'entity_code' => $entityCode,
                    'contact' => $row,
                ]);

                continue;
            }

            $branchCode = trim((string) ($row['SUTRABCON'] ?? ''));
            $owners = $branches->get($branchCode, collect());
            $owner = $branchCode !== '' && $owners->count() === 1 ? $owners->first() : null;

            if (! $owner instanceof User) {
                Log::error('El contacto no trae un código de sucursal que SCR pueda procesar.', [
                    'entity_code' => $entityCode,
                    'rut' => $attributes['rut'],
                    'branch_code' => $branchCode,
                    'reason' => match (true) {
                        $branchCode === '' => 'missing',
                        $owners->count() > 1 => 'ambiguous',
                        default => 'no_match',
                    },
                ]);
                $skippedRuts[] = $attributes['rut'];

                continue;
            }

            $attributes['user_id'] = $owner->id;

            Contact::updateOrCreate(
                [
                    'entity_code' => $attributes['entity_code'],
                    'rut' => $attributes['rut'],
                ],
                $attributes,
            );
        }

        Contact::query()
            ->where('entity_code', $entityCode)
            ->where(function ($query) use ($startedAt) {
                $query->whereNull('random_synced_at')
                    ->orWhere('random_synced_at', '<', $startedAt);
            })
            ->when(
                $skippedRuts !== [],
                fn ($query) => $query->whereNotIn('rut', $skippedRuts),
            )
            ->delete();
    }
}
