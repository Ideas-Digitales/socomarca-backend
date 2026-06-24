<?php

namespace App\Jobs;

use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        /** @var array $entidades */
        $entidades = $randomApi->fetchAndUpdateUsers();

        Log::info('Entidades: ' . json_encode($entidades));

        foreach ($entidades as $entidad) {
            try {
                if ($entidad['TIPOSUC'] == 'P') { //Sincroniza solo si es sucursal principal

                    $user = \App\Models\User::where('user_code', $entidad['KOEN'])->first();
                    $rten = \Illuminate\Support\Facades\DB::table('users')
                        ->where('rut', $entidad['RTEN'])
                        ->first(['id']);

                    if ($user && $rten && $user->id !== $rten->id) {
                        Log::warning('Skipping random user sync', [
                            'message' => 'RUT already exists in the database',
                            'user_found_by_code' => $user->toArray(),
                            'user_found_by_rut' => (array) $rten,
                            'entidad_random' => $entidad,
                        ]);
                        continue;
                    }

                    if ($user === null) {
                        $user = new \App\Models\User();
                    }

                    $email = trim($entidad['EMAILCOMER'] ?? '') ?: null;
                    if (!$email) {
                        $rut = $entidad['KOEN'] ?? 'user';
                        $email = "temp_{$rut}@socomarca.temp";
                    }

                    if (\App\Models\User::where('email', $email)->exists() && $user->id === null) {
                        Log::warning('Skipping random user sync', [
                            'message' => 'Email address already exists in the database',
                            'user' => $user->toArray(),
                            'entidad_random' => $entidad,
                            'email' => $email,
                        ]);
                        continue;
                    }

                    Log::info('Processing user', ['user' => $user->toArray()]);

                    $user->user_code        = $entidad['KOEN'];
                    $user->rut              = $entidad['RTEN'];
                    $user->name             = $entidad['NOKOEN'] ?? '';
                    $user->email            = $email;
                    $user->business_name    = $entidad['SIEN'] ?? '';
                    $user->is_active        = true;
                    $user->phone            = $entidad['FOEN'] ?? null;
                    $user->branch_code      = $entidad['SUEN'] ?? '';
                    $user->random_user_type = $entidad['TIEN'];

                    if ($user->id === null) {
                        $user->password = bcrypt('password');
                    }

                    $user->save();
                    $user->refresh();

                    if (in_array($user->random_user_type, ['C', 'A'])) {
                        $user->assignRole('customer');
                    } else {
                        Log::warning('User doesn\'t have a valid random TIEN to assign a role', [
                            'user' => $user->toArray(),
                            'entidad_random' => $entidad,
                        ]);
                    }

                    Log::info("User {$user->id}, with RUT {$user->rut} and code {$user->user_code} synced successfully");
                }
            } catch (\Throwable $e) {
                Log::error('SyncRandomUsers failed: ' . $e->getMessage());
                return;
            }
        }
        Log::info('SyncRandomUsers completed successfully');
    }
}
