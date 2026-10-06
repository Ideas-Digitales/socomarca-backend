<?php

namespace App\Listeners;

use App\Events\EntityEmailIssuesDetected;
use Illuminate\Support\Facades\Log;

class LogEntityEmailIssues
{
    /**
     * Handle the event.
     */
    public function handle(EntityEmailIssuesDetected $event): void
    {
        Log::warning('Random entities with commercial email (EMAILCOMER) issues detected', [
            'duplicated_emails_count' => count($event->duplicatedEmails),
            'missing_emails_count' => count($event->missingEmails),
            'duplicated_emails' => $event->duplicatedEmails,
            'missing_emails' => $event->missingEmails,
        ]);
    }
}
