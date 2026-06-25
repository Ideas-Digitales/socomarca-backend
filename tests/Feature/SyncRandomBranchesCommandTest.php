<?php

use App\Jobs\SyncRandomBranches;

describe('random:sync-branches command', function () {
    it('dispatches the SyncRandomBranches job to the correct queue', function () {
        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('random:sync-branches')
            ->expectsOutput('Queuing branches sync job')
            ->expectsOutput('Branches sync job has been queued successfully')
            ->assertExitCode(0);

        \Illuminate\Support\Facades\Queue::assertPushedOn(
            'random-branches',
            SyncRandomBranches::class
        );
    });
});
