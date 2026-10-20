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
 * Copies the contacts of every Random entity that has a user in Compra Rápida.
 *
 * Contact sync is disabled: nothing schedules or dispatches this job. The class stays so the
 * pull can be turned back on. New contacts are attached to the primary branch user of the
 * entity. The branch is not read from Random.
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

            $existing = Contact::query()
                ->where('entity_code', $attributes['entity_code'])
                ->where('rut', $attributes['rut'])
                ->first();

            if ($existing === null) {
                $owner = User::query()
                    ->where('user_code', $entityCode)
                    ->orderByRaw("case when branch_type = 'P' then 0 else 1 end")
                    ->orderBy('id')
                    ->first();

                if ($owner === null) {
                    Log::warning('SyncRandomContacts skipped contact without a branch user', [
                        'entity_code' => $entityCode,
                        'rut' => $attributes['rut'],
                    ]);

                    continue;
                }

                $attributes['user_id'] = $owner->id;
            }

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
            ->delete();
    }
}
