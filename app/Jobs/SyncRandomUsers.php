<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncRandomUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
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

        $entidades = [];
        $size = 100;
        $page = 1;

        do {
            /** @var array $entidades */
            $entidades = $randomApi->getEntidadesUsuarios($size, $page);

            Log::debug("Random Entidades", [
                'count' => count($entidades),
            ]);

            foreach ($entidades as $entidad) {
                try {
                    Log::debug("Entidad -> KOEN: {$entidad['KOEN']}, RTEN: {$entidad['RTEN']}");
                    if ($entidad['TIPOSUC'] == 'P') { //Sincroniza solo si es sucursal principal
                        if (empty($entidad['KOEN'])) {
                            Log::warning('Random entidad without KOEN fetched', $entidad);
                        }

                        if (empty($entidad['RTEN'])) {
                            Log::warning('Random entidad without RTEN fetched', $entidad);
                        }

                        Log::info("Processing Random Entidad. KOEN: {$entidad['KOEN']}, RTEN: {$entidad['RTEN']}");

                        $email = trim($entidad['EMAILCOMER'] ?? '') ?: null;
                        if (!$email) {
                            $rut = $entidad['RTEN'] ?? 'user';
                            $email = "temp_{$rut}@socomarca.temp";
                        }

                        $pricesLists = $entidad['KOLTVEN'] ?? [];
                        $jsonPricesLists = json_encode($pricesLists);

                        User::upsert(
                            [
                                [
                                    'user_code'        => $entidad['KOEN'],
                                    'rut'              => $entidad['RTEN'],
                                    'name'             => $entidad['NOKOEN'] ?? '',
                                    'email'            => $email,
                                    'business_name'    => $entidad['SIEN'] ?? '',
                                    'is_active'        => true,
                                    'phone'            => $entidad['FOEN'] ?? null,
                                    'branch_code'      => $entidad['SUEN'] ?? '',
                                    'random_user_type' => $entidad['TIEN'],
                                    'password'         => bcrypt('password'),
                                    'prices_lists' => $jsonPricesLists,
                                ],
                            ],
                            uniqueBy: ['rut'],
                            update: [
                                'user_code',
                                'name',
                                'email',
                                'business_name',
                                'is_active',
                                'phone',
                                'branch_code',
                                'random_user_type',
                                'prices_lists',
                            ]
                        );

                        $user = User::where('rut', $entidad['RTEN'])->firstOrFail();

                        DB::table('branches')->upsert( // Sync primary branch
                            [
                                [
                                    'code' => $entidad['SUEN'],
                                    'user_code' => $entidad['KOEN'],
                                    'name' => $entidad['NOKOEN'] ?? '',
                                    'email' => $entidad['EMAIL'] ?? '',
                                    'commercial_email' => $entidad['EMAILCOMER'] ?? '',
                                    'phone' => $entidad['FOEN'] ?? '',
                                    'rut' => $entidad['RTEN'],
                                    'business_name' => $entidad['SIEN'] ?? '',
                                    'user_id' => $user->id,
                                    'branch_type' => $entidad['TIPOSUC'],
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

                        if (in_array($user->random_user_type, ['C', 'A'])) {
                            $user->assignRole('customer');
                        } else {
                            Log::warning('User doesn\'t have a valid random TIEN to assign a role', [
                                'user' => $user->toArray(),
                                'entidad_random' => $entidad,
                            ]);
                        }

                        Log::info("User {$user->id}, with RUT {$user->rut} and code {$user->user_code} successfully synced");
                    }
                } catch (\Throwable $e) {
                    Log::error('SyncRandomUsers failed: ' . $e->getMessage());
                    return;
                }
            }

            $page++;
        } while (!empty($entidades));

        Log::info('SyncRandomUsers completed successfully');
    }
}
