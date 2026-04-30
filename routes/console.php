<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Jobs\CheckBlockedCreditLinesJob;
use Illuminate\Support\Facades\Schedule;

if (config('random.credit_sync.enabled', true)) {
    $frequency = max(1, (int) config('random.credit_sync.frequency_minutes', 5));
    Schedule::job(new CheckBlockedCreditLinesJob)->cron("*/{$frequency} * * * *");
}
