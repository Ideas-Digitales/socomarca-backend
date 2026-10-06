<?php

use App\Jobs\SyncRandomBrands;
use App\Jobs\SyncRandomCategories;
use App\Jobs\SyncRandomPrices;
use App\Jobs\SyncRandomProducts;
use App\Jobs\SyncRandomStock;
use App\Jobs\SyncRandomUsers;
use Illuminate\Support\Facades\Queue;

/**
 * The queues must match the workers defined in supervisord.conf.
 */
it('queues the sync job on the queue processed by supervisor', function (string $command, string $job, string $queue) {
    Queue::fake();

    $this->artisan($command)->assertSuccessful();

    Queue::assertPushedOn($queue, $job);
})->with([
    'categories' => ['random:sync-categories', SyncRandomCategories::class, 'random-sync-products'],
    'brands' => ['random:sync-brands', SyncRandomBrands::class, 'random-sync-products'],
    'products' => ['random:sync-products', SyncRandomProducts::class, 'random-sync-products'],
    'prices' => ['random:sync-prices', SyncRandomPrices::class, 'random-sync-products'],
    'stock' => ['random:sync-stock', SyncRandomStock::class, 'random-sync-products'],
    'users' => ['random:sync-users', SyncRandomUsers::class, 'random-sync-users'],
]);

it('queues each job of the full sync chain on the queue processed by supervisor', function () {
    Queue::fake();

    $this->artisan('random:sync-all')->assertSuccessful();

    Queue::assertPushedOn('random-sync-products', SyncRandomCategories::class, function (SyncRandomCategories $job) {
        $chained = array_map(fn (string $serialized) => unserialize($serialized), $job->chained);
        $queues = array_map(fn ($chainedJob) => [get_class($chainedJob), $chainedJob->queue], $chained);

        return $queues === [
            [SyncRandomBrands::class, 'random-sync-products'],
            [SyncRandomProducts::class, 'random-sync-products'],
            [SyncRandomPrices::class, 'random-sync-products'],
            [SyncRandomStock::class, 'random-sync-products'],
            [SyncRandomUsers::class, 'random-sync-users'],
        ];
    });
});
