<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Sincronizar productos cada día a las 3 AM
        $schedule->command('random:sync-products')
                ->dailyAt('03:00')
                ->withoutOverlapping();

        // Sincronizar usuarios cada día a las 1 AM
        $schedule->command('random:sync-users')
                ->dailyAt('01:00')
                ->withoutOverlapping();

        // Liberar reservas expiradas cada hora
        $schedule->command('reservations:release-expired')
                ->hourly()
                ->withoutOverlapping();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 