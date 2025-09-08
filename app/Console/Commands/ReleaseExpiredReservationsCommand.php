<?php

namespace App\Console\Commands;

use App\Events\CartItemRemoved;
use App\Models\CartItem;
use App\Models\ProductStock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:release-expired {--dry-run : Show what would be released without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired stock reservations from cart items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $expirationMinutes = env('CART_RESERVATION_TIMEOUT', 1440); // Default 24 hours
        $cutoffTime = Carbon::now()->subMinutes($expirationMinutes);

        $this->info("Looking for reservations older than {$expirationMinutes} minutes (before {$cutoffTime->toDateTimeString()})");

        $expiredItems = CartItem::whereNotNull('warehouse_id')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $cutoffTime)
            ->with(['product', 'warehouse'])
            ->get();

        if ($expiredItems->isEmpty()) {
            $this->info('No expired reservations found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$expiredItems->count()} expired cart items with reservations:");

        $totalReleasedQuantity = 0;
        $processedItems = 0;

        foreach ($expiredItems as $item) {
            $reservedMinutes = Carbon::now()->diffInMinutes($item->reserved_at);
            
            $this->line(sprintf(
                "- User: %d, Product: %s (%d), Quantity: %d, Reserved: %d min ago, Warehouse: %s",
                $item->user_id,
                $item->product->name ?? 'Unknown',
                $item->product_id,
                $item->quantity,
                $reservedMinutes,
                $item->warehouse->name ?? 'Unknown'
            ));

            if (!$isDryRun) {
                DB::transaction(function () use ($item, &$totalReleasedQuantity, &$processedItems) {
                    // Liberar stock reservado
                    $productStock = ProductStock::where('product_id', $item->product_id)
                        ->where('warehouse_id', $item->warehouse_id)
                        ->where('unit', $item->unit)
                        ->first();

                    if ($productStock) {
                        $productStock->releaseStock($item->quantity);
                        $totalReleasedQuantity += $item->quantity;
                        
                        Log::info("Released expired reservation", [
                            'user_id' => $item->user_id,
                            'product_id' => $item->product_id,
                            'warehouse_id' => $item->warehouse_id,
                            'quantity' => $item->quantity,
                            'reserved_at' => $item->reserved_at,
                        ]);
                    }

                    // Disparar evento antes de eliminar
                    event(new CartItemRemoved($item));

                    // Eliminar item del carrito
                    $item->delete();
                    $processedItems++;
                });
            }
        }

        if ($isDryRun) {
            $this->warn('This was a dry run. No reservations were actually released.');
            $this->info('Use --dry-run=false or remove the flag to actually release the reservations.');
        } else {
            $this->info("Successfully processed {$processedItems} expired cart items");
            $this->info("Total quantity released: {$totalReleasedQuantity}");
            
            Log::info('Expired reservations cleanup completed', [
                'processed_items' => $processedItems,
                'total_released_quantity' => $totalReleasedQuantity,
                'expiration_minutes' => $expirationMinutes,
            ]);
        }

        return Command::SUCCESS;
    }
}