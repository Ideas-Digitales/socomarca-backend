<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;

use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;



class SyncRandomProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tipr;
    protected $page;
    protected $size;

    public function __construct($tipr = 'FPN', $page = 1, $size = 100)
    {
        $this->tipr = $tipr;
        $this->page = $page;
        $this->size = $size;
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomProducts started');
        try {
            $products = $randomApi->getProducts($this->tipr);
            $rows = $products['data'];

            if ($rows === []) {
                Log::error('SyncRandomProducts skipped deactivation: the product list was empty');

                return;
            }

            DB::transaction(function () use ($rows) {
                $syncedProductIds = [];

                foreach ($rows as $product) {
                    $brand = null;
                    $family = $product['FMPR'] ?? '';
                    $group = $product['PFPR'] ?? '';
                    $subgroup = $product['HFPR'] ?? '';

                    // Codes repeat inside a level. The Random key is the path of codes:
                    // FMPR, FMPR/PFPR, FMPR/PFPR/HFPR.
                    $familyKey = $family;
                    $groupKey = ($family !== '' && $group !== '') ? $family.'/'.$group : '';
                    $subgroupKey = ($groupKey !== '' && $subgroup !== '') ? $groupKey.'/'.$subgroup : '';

                    $supercategory = $familyKey !== ''
                        ? Category::where('level', 1)->where('key', $familyKey)->first()
                        : null;
                    $category = $groupKey !== ''
                        ? Category::where('level', 2)->where('key', $groupKey)->first()
                        : null;
                    $subcategory = $subgroupKey !== ''
                        ? Category::where('level', 3)->where('key', $subgroupKey)->first()
                        : null;

                    if (! empty($product['MRPR'])) {
                        $brand = Brand::where('random_erp_code', $product['MRPR'])->first();
                    }

                    $data = [
                        'random_product_id' => $product['KOPR'],
                        'sku' => $product['KOPR'],
                        'name' => $product['NOKOPR'],
                        'description' => null,
                        'brand_id' => $brand ? $brand->id : null,
                        'status' => true,
                    ];

                    $this->putCategoryId($data, 'supercategory_id', $family, $supercategory, $product['KOPR'], $familyKey);
                    $this->putCategoryId($data, 'category_id', $group, $category, $product['KOPR'], $groupKey);
                    $this->putCategoryId($data, 'subcategory_id', $subgroup, $subcategory, $product['KOPR'], $subgroupKey);

                    Product::updateOrCreate(
                        ['random_product_id' => $product['KOPR']], $data
                    );

                    $syncedProductIds[] = $product['KOPR'];
                }

                Product::whereNotIn('random_product_id', $syncedProductIds)
                    ->where('status', true)
                    ->update(['status' => false]);
            });

            Log::info('SyncRandomProducts finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando productos: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * An empty code means Random has no category at that level. A code whose key
     * is not in the catalog yet is left untouched so a later category sync can
     * still match the product's current assignment.
     *
     * @param  array<string, mixed>  $data
     */
    private function putCategoryId(array &$data, string $column, string $code, ?Category $match, string $productCode, string $key): void
    {
        if ($code === '') {
            $data[$column] = null;

            return;
        }

        if ($match !== null) {
            $data[$column] = $match->id;

            return;
        }

        Log::warning('SyncRandomProducts kept the previous category because the key was not found', [
            'kopr' => $productCode,
            'column' => $column,
            'key' => $key !== '' ? $key : $code,
        ]);
    }
}
