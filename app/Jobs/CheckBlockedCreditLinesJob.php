<?php

namespace App\Jobs;

use App\Models\CreditLine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckBlockedCreditLinesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $blockedLines = CreditLine::where('is_blocked', true)->get();

        foreach ($blockedLines as $creditLine) {
            ProcessPendingCreditPaymentJob::dispatch($creditLine);
        }
    }
}
