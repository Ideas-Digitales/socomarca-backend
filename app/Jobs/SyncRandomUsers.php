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
    public function __construct()
    {
        $this->onQueue('random-users');
    }

    /**
     * Execute the job.
     */
    public function handle(RandomApiService $randomApi): void
    {
         Log::info('SyncRandomUsers started');
        try {
            $entidades = $randomApi->fetchAndUpdateUsers();
            
            Log::info('Entidades: ' . json_encode($entidades));
            
            foreach ($entidades as $entidad) {
                if($entidad['TIPOSUC'] == 'P') { //Sincroniza solo si es sucursal principal
                    
                    $user = \App\Models\User::firstOrNew(['rut' => $entidad['KOEN'] ?? null]);

                    // Preparar email - generar temporal si está vacío
                    $email = trim($entidad['EMAILCOMER'] ?? '') ?: null;
                    if (!$email) {
                        // Generar email temporal basado en RUT
                        $rut = $entidad['KOEN'] ?? 'user';
                        $email = "temp_{$rut}@socomarca.temp";
                    }
                    
                    // Validar que no exista otro usuario con el mismo email
                    if (\App\Models\User::where('email', $email)->exists()) {
                        Log::warning('Email ya existe en la base de datos: ' . $email . ' - Omitiendo usuario RUT: ' . ($entidad['KOEN'] ?? 'N/A'));
                        continue;
                    }

                    $user->name          = $entidad['NOKOEN'] ?? '';
                    $user->email         = $email;
                    $user->business_name = $entidad['SIEN'] ?? '';
                    $user->is_active     = true;
                    $user->phone         = $entidad['FOEN'] ?? null;
                    // Solo asigna password si es un usuario nuevo
                    if (!$user->exists) {
                        $user->password = bcrypt('password');
                    }

                    $user->save();
                }
            
            }
            Log::info('SyncRandomUsers completed successfully');
        } catch (\Exception $e) {
            Log::error('SyncRandomUsers failed: ' . $e->getMessage());
        }
    }
}
