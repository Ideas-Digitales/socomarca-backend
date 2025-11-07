<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;

use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $this->onQueue('random-products');
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomProducts started');
        try {
            $products = $randomApi->getProducts($this->tipr);

            foreach ($products['data'] as $product) {

                $category = $subcategory = $brand = null;
                if(!empty($product['FMPR'])){
                    $category = Category::where('code', $product['FMPR'])->first();
                }
                if(!empty($product['PFPR'])){
                    $subcategory = Subcategory::where('code', $product['PFPR'])->first();
                }
                if(!empty($product['MRPR'])){
                    $brand = Brand::where('random_erp_code', $product['MRPR'])->first();
                }
    
                $data = [
                    'random_product_id' => $product['KOPR'],
                    'sku' => $product['KOPR'],
                    'name' => $product['NOKOPR'],
                    'description' => null,
                    'brand_id' => $brand ? $brand->id : null,
                    'category_id' => $category ? $category->id : null,
                    'subcategory_id' => $subcategory ? $subcategory->id : null,
                    'status' => true,
                ];
                
                //Update or create product
                Product::updateOrCreate(
                    ['random_product_id' => $product['KOPR']], $data
                );
            }
            
            Log::info('SyncRandomProducts finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando productos: ' . $e->getMessage());
            throw $e;
        }
    }
} 