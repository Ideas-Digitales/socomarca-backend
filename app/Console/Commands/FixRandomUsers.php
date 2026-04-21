<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixRandomUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:fix-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix random users';

    /**
     * Execute the console command.
     */
    public function handle(RandomApiService $random)
    {
        Log::info('SyncRandomUsers started');

        /** @var array $entidades */
        $entidades = $random->fetchAndUpdateUsers();


        foreach ($entidades as $entidad) {
            try {
                if ($entidad['TIPOSUC'] == 'P') { //Sincroniza solo si es sucursal principal

                    $user = User::where('user_code', $entidad['KOEN'])->first();
                    $rten = User::where('rut', $entidad['RTEN'])->first();

                    if ($user && $rten && $user->id !== $rten->id) {
                        Log::warning('Skipping random user sync', [
                            'message' => 'RUT already exists in the database',
                            'user_found_by_code' => $user->toArray(),
                            'user_found_by_rut' => $rten->toArray(),
                            'entidad_random' => $entidad,
                        ]);
                        continue;
                    }

                    if ($user === null) {
                        $user = new User();
                    }

                    $email = trim($entidad['EMAILCOMER'] ?? '') ?: null;
                    if (!$email) {
                        // Generar email temporal basado en RUT
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

                    $user->rut          = $entidad['RTEN'];
                    $user->name          = $entidad['NOKOEN'] ?? '';
                    $user->email         = $email;
                    $user->business_name = '';
                    $user->is_active     = true;
                    $user->phone         = $entidad['FOEN'] ?? null;
                    $user->branch_code = $entidad['SUEN'] ?? '';
                    $user->random_user_type = $entidad['TIEN'];

                    // Solo asigna password si es un usuario nuevo
                    if (!$user->exists) {
                        $user->password = bcrypt('password');
                    }

                    $user->save();
                    $user->refresh();

                    if (in_array($user->random_user_type, ['C', 'A'])) {
                        $user->assignRole('customer');
                        Log::debug('Customer role assigned to user');
                    }

                    Log::info("User {$user->id}, with RUT {$user->rut} and code {$user->user_code} synced successfully");
                }
            } catch (\Throwable $e) {
                # code...
                Log::error('SyncRandomUsers failed: ' . $e->getMessage());
                return;
            }
        }
    }
}
