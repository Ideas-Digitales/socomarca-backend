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

    public function handle(RandomApiService $randomApi)
    {
        Log::info('SyncRandomCategories started');
        try {
            $categories = $randomApi->getCategories();

            $nivel1 = [];
            $nivel2 = [];
            $nivel3 = [];

            foreach ($categories['data'] as $category) {
                if ($category['NIVEL'] == 1) {
                    $nivel1[] = $category;
                } elseif ($category['NIVEL'] == 2) {
                    $nivel2[] = $category;
                } elseif ($category['NIVEL'] == 3) {
                    $nivel3[] = $category;
                }
            }

            $nivel1Codes = [];
            foreach ($nivel1 as $category) {
                Category::updateOrCreate(
                    ['code' => $category['CODIGO'], 'level' => 1],
                    [
                        'name' => $category['NOMBRE'],
                        'key' => $category['LLAVE'],
                        'enabled' => true
                    ]
                );
                $nivel1Codes[] = $category['CODIGO'];
            }

            $subcategoryKeys = [];
            foreach ($nivel2 as $category) {
                $parts = explode("/", $category['LLAVE']);
                $parentCategory = Category::where('code', $parts[0])->where('level', 1)->first();

                if ($parentCategory) {
                    Subcategory::updateOrCreate(
                        ['key' => $category['LLAVE']],
                        [
                            'code' => $category['CODIGO'],
                            'name' => $category['NOMBRE'],
                            'level' => $category['NIVEL'],
                            'category_id' => $parentCategory->id,
                            'enabled' => true
                        ]
                    );
                    $subcategoryKeys[] = $category['LLAVE'];
                }
            }

            foreach ($nivel3 as $category) {
                $parts = explode("/", $category['LLAVE']);
                $parentKey = $parts[0] . '/' . $parts[1];
                $parentSubcategory = Subcategory::where('key', $parentKey)->first();

                if ($parentSubcategory) {
                    Subcategory::updateOrCreate(
                        ['key' => $category['LLAVE']],
                        [
                            'code' => $category['CODIGO'],
                            'name' => $category['NOMBRE'],
                            'level' => $category['NIVEL'],
                            'category_id' => $parentSubcategory->category_id,
                            'enabled' => true
                        ]
                    );
                    $subcategoryKeys[] = $category['LLAVE'];
                }
            }

            Category::where('level', 1)
                ->whereNotIn('code', $nivel1Codes)
                ->update(['enabled' => false]);

            Subcategory::whereNotIn('key', $subcategoryKeys)
                ->update(['enabled' => false]);

            Log::info('SyncRandomCategories finished');
        } catch (\Exception $e) {
            Log::error('Error sincronizando categorías: ' . $e->getMessage());
            throw $e;
        }
    }
}