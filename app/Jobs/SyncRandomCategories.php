<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Subcategory;
use App\Services\RandomApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRandomCategories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('random-categories');
    }

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomCategories started');
        try {
            $categories = $randomApi->getCategories();

            foreach ($categories['data'] as $category) {
                if($category['NIVEL'] == 1){
                    Category::updateOrCreate(
                        ['code' => $category['CODIGO']],
                        [
                            'name' => $category['NOMBRE'],
                            'level' => $category['NIVEL'],
                            'key' => $category['LLAVE']
                        ]
                    );
                }
                if($category['NIVEL'] == 2){
                    $parent = explode("/", $category['LLAVE']);
                    $parentCategory = Category::where('code', $parent[0])->first();
                    Subcategory::updateOrCreate(
                        ['key' => $category['LLAVE']],
                        [
                            'code' => $category['CODIGO'],
                            'name' => $category['NOMBRE'],
                            'level' => $category['NIVEL'],
                            'category_id' => $parentCategory->id
                        ]
                    );
                }

                if($category['NIVEL'] == 3){
                    $parent = explode("/", $category['LLAVE']);
                    $parentCategory = Category::where('code', $parent[0])->first();
                    Subcategory::updateOrCreate(
                        ['key' => $category['LLAVE']],
                        [
                            'code' => $category['CODIGO'],
                            'name' => $category['NOMBRE'],
                            'level' => $category['NIVEL'],
                            'category_id' => $parentCategory->id
                        ]
                    );
                }
            }
            
            Log::info('SyncRandomCategories finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando categorías: ' . $e->getMessage());
            throw $e;
        }
    }
} 