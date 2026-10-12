<?php

namespace App\Jobs;

use App\Models\Price;
use App\Models\Product;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncRandomPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomPrices started');

        try {
            $priceListCodes = DB::table('users')
                ->whereNotNull('prices_lists')
                ->selectRaw("distinct jsonb_array_elements_text(prices_lists) as price_code")
                ->pluck('price_code')
                ->toArray();

            if (empty($priceListCodes)) {
                Log::alert('No price list codes found in users');
                return;
            }

            Log::info('Found price list codes', ['codes' => $priceListCodes]);

            foreach ($priceListCodes as $priceListCode) {
                Log::info("Processing price list: {$priceListCode}");

                $page = 1;
                $size = 100;
                $priceListName = $priceListCode;
                $syncedKeys = [];

                do {
                    $response = $randomApi->getPricesLists($priceListCode, $size, $page);

                    // Random answers errors with a "message" and no "datos" key, so only an empty
                    // "datos" means the whole list was read and the missing prices can be deactivated.
                    if (!isset($response['datos'])) {
                        $message = $response['message'] ?? 'no datos in response';
                        Log::warning("Price list {$priceListCode} sync stopped at page {$page}: {$message}");
                        break;
                    }

                    $priceListName = $response['nombre'] ?? $priceListName;

                    if (empty($response['datos'])) {
                        Log::debug("No more data for price list {$priceListCode} at page {$page}");
                        $this->deactivateStalePrices($priceListName, $syncedKeys);
                        break;
                    }

                    Log::debug("Price list {$priceListCode}, page {$page}", [
                        'count' => count($response['datos']),
                    ]);

                    $pricesToUpsert = [];

                    foreach ($response['datos'] as $price) {
                        $units = $price['unidades'] ?? [];

                        if (empty($units)) {
                            continue;
                        }

                        // "venderen" is the 1-based position of the unit the product is sold in.
                        $sellUnit = (int) ($price['venderen'] ?? 1);
                        $unit = $units[$sellUnit - 1] ?? null;

                        if ($unit === null) {
                            Log::warning("Unit {$sellUnit} not found for random_product_id {$price['kopr']} in price list {$priceListCode}, using the first unit");
                            $unit = $units[0];
                        }

                        $product = Product::where('random_product_id', $price['kopr'])->first();

                        if (!$product) {
                            Log::warning("Product with random_product_id {$price['kopr']} not found");
                            continue;
                        }

                        $pricesToUpsert[] = [
                            'product_id' => $product->id,
                            'random_product_id' => $price['kopr'],
                            'price_list_id' => $priceListName,
                            'unit' => $unit['nombre'],
                            'price' => $unit['prunneto'][0]['f'] ?? 0,
                            'valid_from' => null,
                            'valid_to' => null,
                            'is_active' => true,
                        ];
                        $syncedKeys["{$price['kopr']}|{$unit['nombre']}"] = true;
                    }

                    if (!empty($pricesToUpsert)) {
                        Price::upsert(
                            $pricesToUpsert,
                            uniqueBy: ['random_product_id', 'price_list_id', 'unit'],
                            update: ['product_id', 'price', 'valid_from', 'valid_to', 'is_active']
                        );

                        Log::debug("Upserted " . count($pricesToUpsert) . " prices for {$priceListCode}");
                    }

                    $page++;
                } while (true);
            }

            Log::info('SyncRandomPrices finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando precios: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deactivate the prices of a list that Random no longer returns, e.g. products removed
     * from the list or whose sale unit changed.
     *
     * @param string $priceListName Value stored in prices.price_list_id
     * @param array<string, true> $syncedKeys "random_product_id|unit" keys returned by Random
     */
    private function deactivateStalePrices(string $priceListName, array $syncedKeys): void
    {
        $staleIds = Price::where('price_list_id', $priceListName)
            ->where('is_active', true)
            ->get(['id', 'random_product_id', 'unit'])
            ->reject(fn (Price $price) => isset($syncedKeys["{$price->random_product_id}|{$price->unit}"]))
            ->pluck('id');

        foreach ($staleIds->chunk(1000) as $ids) {
            Price::whereIn('id', $ids)->update(['is_active' => false]);
        }

        if ($staleIds->isNotEmpty()) {
            Log::info("Deactivated {$staleIds->count()} prices no longer returned for price list {$priceListName}");
        }
    }
}
