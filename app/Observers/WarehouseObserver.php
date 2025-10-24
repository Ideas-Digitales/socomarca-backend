<?php

namespace App\Observers;

use App\Models\Warehouse;

class WarehouseObserver
{
    /**
     * Handle the Warehouse "updated" event.
     */
    public function updated(Warehouse $warehouse): void
    {
        // Check if priority was changed and the new value is 1 (default warehouse)
        if ($warehouse->wasChanged('priority') && $warehouse->priority == 1) {
            // Reset all other warehouses priority to 999, except this one
            Warehouse::where('id', '!=', $warehouse->id)
                ->update(['priority' => 999]);
        }
    }
}