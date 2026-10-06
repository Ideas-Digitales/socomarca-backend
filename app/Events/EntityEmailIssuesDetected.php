<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched at the end of the Random entities sync when synced users share a commercial
 * email (EMAILCOMER) or do not have one. Those users cannot log in until the client fixes
 * the data in Random.
 */
class EntityEmailIssuesDetected
{
    use Dispatchable;

    /**
     * Each entry has the keys IDMAEEN, KOEN, SUEN, NOKOEN and EMAILCOMER.
     *
     * @param list<array{IDMAEEN: int, KOEN: string, SUEN: string|null, NOKOEN: string, EMAILCOMER: string|null}> $duplicatedEmails Users whose normalized email is assigned to more than one synced user
     * @param list<array{IDMAEEN: int, KOEN: string, SUEN: string|null, NOKOEN: string, EMAILCOMER: string|null}> $missingEmails Users without email
     */
    public function __construct(
        public readonly array $duplicatedEmails,
        public readonly array $missingEmails,
    ) {
    }
}
