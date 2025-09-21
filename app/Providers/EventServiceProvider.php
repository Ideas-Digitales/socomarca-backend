<?php

namespace App\Providers;

use App\Events\CartItemRemoved;
use App\Events\OrderCompleted;
use App\Events\OrderFailed;
use App\Listeners\ReleaseReservedStock;
use App\Models\Warehouse;
use App\Observers\WarehouseObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderCompleted::class => [
            ReleaseReservedStock::class,
        ],
        OrderFailed::class => [
            ReleaseReservedStock::class,
        ],
        CartItemRemoved::class => [
            ReleaseReservedStock::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Warehouse::observe(WarehouseObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}