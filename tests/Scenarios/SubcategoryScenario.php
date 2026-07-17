<?php

namespace Tests\Scenarios;

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;

class SubcategoryScenario
{

    public function __construct(
        public User $user,
        public Category $category,
        public Subcategory $subcategory,
    ) {}

    public array $listJsonStructure = [
        'data' => [
            [
                'id',
                'name',
                'description',
                'code',
                'level',
                'key',
                'category' => [
                    'id',
                    'name',
                    'description',
                    'code',
                    'level',
                    'key',
                    'created_at',
                    'updated_at',
                ],
                'created_at',
                'updated_at',
            ],
        ],
    ];

    public static function make(): SubcategoryScenario
    {
        $user = User::factory()->create();
        $user->givePermissionTo("read-all-subcategories");

        $category = \App\Models\Category::factory()->create();
        $subcategory = \App\Models\Subcategory::factory()->create([
            'category_id' => $category->id,
        ]);
        return new SubcategoryScenario($user, $category, $subcategory);
    }
}
