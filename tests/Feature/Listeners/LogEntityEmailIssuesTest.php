<?php

use App\Events\EntityEmailIssuesDetected;
use App\Listeners\LogEntityEmailIssues;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

test('logs a warning with the complete list of email issues', function () {
    Log::spy();

    $duplicated = [
        ['IDMAEEN' => 1, 'KOEN' => '77528378', 'SUEN' => 'A', 'NOKOEN' => 'Principal', 'EMAILCOMER' => 'compartido@example.com'],
        ['IDMAEEN' => 3, 'KOEN' => '77528378', 'SUEN' => 'C', 'NOKOEN' => 'Tercera', 'EMAILCOMER' => 'compartido@example.com'],
    ];
    $missing = [
        ['IDMAEEN' => 2, 'KOEN' => '77528378', 'SUEN' => 'B', 'NOKOEN' => 'Sin email', 'EMAILCOMER' => null],
    ];

    (new LogEntityEmailIssues())->handle(new EntityEmailIssuesDetected($duplicated, $missing));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'EMAILCOMER')
            && $context === [
                'duplicated_emails_count' => 2,
                'missing_emails_count' => 1,
                'duplicated_emails' => $duplicated,
                'missing_emails' => $missing,
            ]);
});

test('listens to the EntityEmailIssuesDetected event', function () {
    Event::fake();

    Event::assertListening(EntityEmailIssuesDetected::class, LogEntityEmailIssues::class);
});
