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

                do {
                    $response = $randomApi->getPricesLists($priceListCode, $size, $page);

                    if (!isset($response['datos']) || empty($response['datos'])) {
                        Log::debug("No more data for price list {$priceListCode} at page {$page}");
                        break;
                    }

                    Log::debug("Price list {$priceListCode}, page {$page}", [
                        'count' => count($response['datos']),
                    ]);

                    $pricesToUpsert = [];

                    foreach ($response['datos'] as $price) {
                        $principalUnit = $price['venderen'] ?? 0;
                        $priceListName = $response['nombre'] ?? $priceListCode;

                        foreach ($price['unidades'] ?? [] as $index => $unit) {
                            if ($principalUnit == 1 && $index != 0) {
                                continue;
                            }
                            if ($principalUnit == 2 && $index != 1) {
                                continue;
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
                        }
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
}
