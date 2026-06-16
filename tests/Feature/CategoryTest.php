<?php

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function ()
{
    $this->user = createUser();
    $this->user->givePermissionTo('read-all-categories');
});

describe('Category API', function () {
    it('should require authentication for index', function () {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get(route('categories.index'));
        $response->assertStatus(401);
    });

    it('should return categories with hierarchical structure', function () {
        $cat1 = Category::factory()->create(['level' => 1, 'code' => '0001', 'key' => '0001']);
        $sub2 = Subcategory::factory()->create([
            'category_id' => $cat1->id,
            'level' => 2,
            'code' => '0001',
            'key' => '0001/0001'
        ]);
        $sub3 = Subcategory::factory()->create([
            'category_id' => $cat1->id,
            'level' => 3,
            'code' => '0001',
            'key' => '0001/0001/0001'
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(route('categories.index'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'code',
                    'level',
                    'key',
                    'products_count',
                    'children' => [
                        '*' => [
                            'id',
                            'name',
                            'code',
                            'level',
                            'key',
                            'products_count',
                            'children' => [
                                '*' => [
                                    'id',
                                    'name',
                                    'code',
                                    'level',
                                    'key',
                                    'products_count',
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $data = $response->json();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($cat1->id);
        expect($data[0]['children'])->toHaveCount(1);
        expect($data[0]['children'][0]['id'])->toBe($sub2->id);
        expect($data[0]['children'][0]['children'])->toHaveCount(1);
        expect($data[0]['children'][0]['children'][0]['id'])->toBe($sub3->id);
    });


    it('should filter categories by name', function () {
        Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS', 'code' => '0001', 'key' => '0001']);
        Category::factory()->create(['level' => 1, 'name' => 'REFRIGERADOS', 'code' => '0002', 'key' => '0002']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%CONG%',
                    ]
                ]
            ]);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['name'])->toBe('CONGELADOS');
    });
});