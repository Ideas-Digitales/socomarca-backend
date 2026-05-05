<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Jobs\CheckBlockedCreditLinesJob;
use App\Jobs\SyncRandomBrands;
use App\Jobs\SyncRandomCategories;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomStock;
use App\Jobs\SyncRandomUsers;
use Illuminate\Support\Facades\Schedule;

if (config('random.credit_sync.enabled', true)) {
    $frequency = max(1, (int) config('random.credit_sync.frequency_minutes', 5));
    Schedule::job(new CheckBlockedCreditLinesJob)->cron("*/{$frequency} * * * *");
}

Schedule::job(job: new SyncRandomCategories)->everyTwoHours();
Schedule::job(job: new SyncRandomBrands)->everyTwoHours();
Schedule::job(job: new SyncRandomProducts)->everyTwoHours();
Schedule::job(job: new SyncRandomPrices)->everyTwoHours();
Schedule::job(job: new SyncRandomStock)->everyTwoHours();
Schedule::job(job: new SyncRandomUsers)->everyTwoHours();

