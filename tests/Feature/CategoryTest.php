<?php

use App\Models\Category;
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

    it('should return enabled categories only', function () {
        // Create enabled supercategory with enabled children
        $super1 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $cat2 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub2 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub3 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat2->id, 'enabled' => true]);

        // Create disabled supercategory (should not appear)
        $super2 = Category::factory()->create(['level' => 1, 'enabled' => false]);
        $cat3 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super2->id, 'enabled' => true]);
        $sub4 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat3->id, 'enabled' => true]);

        // Create enabled supercategory with disabled category child
        $super3 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat4 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super3->id, 'enabled' => false]);
        $sub5 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat4->id, 'enabled' => true]);

        // Create enabled supercategory with enabled category but disabled subcategory
        $super4 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat5 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super4->id, 'enabled' => true]);
        $sub6 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => false]);
        $sub7 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => true]);

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
                    'categories' => [
                        '*' => [
                            'id',
                            'name',
                            'code',
                            'level',
                            'key',
                            'products_count',
                            'subcategories' => [
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

        // Should only return enabled supercategories (super1, super3, super4)
        expect($data)->toHaveCount(3);

        $supers = collect($data)->keyBy('name');

        // Validate Super1 hierarchy (fully enabled)
        expect($supers->get($super1->name)['id'])->toBe($super1->id);
        expect($supers->get($super1->name)['level'])->toBe(1);
        expect($supers->get($super1->name)['categories'])->toHaveCount(2);

        $super1Cats = collect($supers->get($super1->name)['categories'])->keyBy('name');
        expect($super1Cats->get($cat1->name)['id'])->toBe($cat1->id);
        expect($super1Cats->get($cat1->name)['level'])->toBe(2);
        expect($super1Cats->get($cat1->name)['subcategories'])->toHaveCount(2);

        $cat1Subs = collect($super1Cats->get($cat1->name)['subcategories'])->keyBy('name');
        expect($cat1Subs->get($sub1->name)['id'])->toBe($sub1->id);
        expect($cat1Subs->get($sub1->name)['level'])->toBe(3);
        expect($cat1Subs->get($sub2->name)['id'])->toBe($sub2->id);

        expect($super1Cats->get($cat2->name)['id'])->toBe($cat2->id);
        expect($super1Cats->get($cat2->name)['subcategories'])->toHaveCount(1);
        expect($super1Cats->get($cat2->name)['subcategories'][0]['id'])->toBe($sub3->id);

        // Validate Super3 exists but disabled category (cat4) should not appear
        expect($supers->get($super3->name)['id'])->toBe($super3->id);
        expect($supers->get($super3->name)['categories'])->toHaveCount(0);

        // Validate Super4 has enabled category but only enabled subcategories
        expect($supers->get($super4->name)['id'])->toBe($super4->id);
        expect($supers->get($super4->name)['categories'])->toHaveCount(1);

        $super4Cats = collect($supers->get($super4->name)['categories'])->keyBy('name');
        expect($super4Cats->get($cat5->name)['id'])->toBe($cat5->id);
        expect($super4Cats->get($cat5->name)['subcategories'])->toHaveCount(1);
        expect($super4Cats->get($cat5->name)['subcategories'][0]['id'])->toBe($sub7->id);

        // Verify disabled supercategory (super2) is not in response
        $super2InResponse = collect($data)->firstWhere('id', $super2->id);
        expect($super2InResponse)->toBeNull();
    });


    it('should return categories with subcategories', function () {
        $super1 = Category::factory()->create(['level' => 1]);
        $super2 = Category::factory()->create(['level' => 1]);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id]);
        $cat2 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id]);
        $cat3 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super2->id]);
        $cat4 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super2->id]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id]);
        $sub2 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id]);
        $sub3 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat2->id]);
        $sub4 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat2->id]);
        $sub5 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat3->id]);
        $sub6 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat3->id]);
        $sub7 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat4->id]);
        $sub8 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat4->id]);

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
                    'categories' => [
                        '*' => [
                            'id',
                            'name',
                            'code',
                            'level',
                            'key',
                            'products_count',
                            'subcategories' => [
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

        // Validate number of supercategories
        expect($data)->toHaveCount(2);

        // Collect supercategories by name for flexible matching
        $supers = collect($data)->keyBy('name');

        // Validate Super1 hierarchy
        expect($supers->get($super1->name)['id'])->toBe($super1->id);
        expect($supers->get($super1->name)['level'])->toBe(1);
        expect($supers->get($super1->name)['categories'])->toHaveCount(2);

        $super1Cats = collect($supers->get($super1->name)['categories'])->keyBy('name');
        expect($super1Cats->get($cat1->name)['id'])->toBe($cat1->id);
        expect($super1Cats->get($cat1->name)['level'])->toBe(2);
        expect($super1Cats->get($cat1->name)['subcategories'])->toHaveCount(2);

        $cat1Subs = collect($super1Cats->get($cat1->name)['subcategories'])->keyBy('name');
        expect($cat1Subs->get($sub1->name)['id'])->toBe($sub1->id);
        expect($cat1Subs->get($sub1->name)['level'])->toBe(3);
        expect($cat1Subs->get($sub2->name)['id'])->toBe($sub2->id);
        expect($cat1Subs->get($sub2->name)['level'])->toBe(3);

        expect($super1Cats->get($cat2->name)['id'])->toBe($cat2->id);
        expect($super1Cats->get($cat2->name)['level'])->toBe(2);
        expect($super1Cats->get($cat2->name)['subcategories'])->toHaveCount(2);

        $cat2Subs = collect($super1Cats->get($cat2->name)['subcategories'])->keyBy('name');
        expect($cat2Subs->get($sub3->name)['id'])->toBe($sub3->id);
        expect($cat2Subs->get($sub4->name)['id'])->toBe($sub4->id);

        // Validate Super2 hierarchy
        expect($supers->get($super2->name)['id'])->toBe($super2->id);
        expect($supers->get($super2->name)['level'])->toBe(1);
        expect($supers->get($super2->name)['categories'])->toHaveCount(2);

        $super2Cats = collect($supers->get($super2->name)['categories'])->keyBy('name');
        expect($super2Cats->get($cat3->name)['id'])->toBe($cat3->id);
        expect($super2Cats->get($cat3->name)['level'])->toBe(2);
        expect($super2Cats->get($cat3->name)['subcategories'])->toHaveCount(2);

        $cat3Subs = collect($super2Cats->get($cat3->name)['subcategories'])->keyBy('name');
        expect($cat3Subs->get($sub5->name)['id'])->toBe($sub5->id);
        expect($cat3Subs->get($sub6->name)['id'])->toBe($sub6->id);

        expect($super2Cats->get($cat4->name)['id'])->toBe($cat4->id);
        expect($super2Cats->get($cat4->name)['level'])->toBe(2);
        expect($super2Cats->get($cat4->name)['subcategories'])->toHaveCount(2);

        $cat4Subs = collect($super2Cats->get($cat4->name)['subcategories'])->keyBy('name');
        expect($cat4Subs->get($sub7->name)['id'])->toBe($sub7->id);
        expect($cat4Subs->get($sub8->name)['id'])->toBe($sub8->id);
    });


    it('should filter categories by name', function () {
        $super1 = Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS', 'code' => '0001', 'key' => '0001']);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);

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

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'code',
                    'level',
                    'key',
                    'products_count',
                    'categories' => [
                        '*' => [
                            'id',
                            'name',
                            'code',
                            'level',
                            'key',
                            'products_count',
                            'subcategories' => [
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
        expect($data[0]['name'])->toBe('CONGELADOS');
        expect($data[0]['id'])->toBe($super1->id);
        expect($data[0]['categories'])->toHaveCount(1);
        expect($data[0]['categories'][0]['id'])->toBe($cat1->id);
        expect($data[0]['categories'][0]['subcategories'])->toHaveCount(1);
        expect($data[0]['categories'][0]['subcategories'][0]['id'])->toBe($sub1->id);
    });


    it('should filter categories by name without disabled categories', function () {
        $super1 = Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS', 'code' => '0001', 'key' => '0001']);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);

        Category::factory()->create(['level' => 1, 'name' => 'REFRIGERADOS', 'code' => '0002', 'key' => '0002']);
        Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS 2', 'code' => '0003', 'key' => '0003', 'enabled' => false]);

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

        $data = $response->json();
        expect($data)->toHaveCount(1);
        expect($data[0]['name'])->toBe('CONGELADOS');
        expect($data[0]['id'])->toBe($super1->id);
        expect($data[0]['categories'])->toHaveCount(1);
        expect($data[0]['categories'][0]['id'])->toBe($cat1->id);
        expect($data[0]['categories'][0]['subcategories'])->toHaveCount(1);
        expect($data[0]['categories'][0]['subcategories'][0]['id'])->toBe($sub1->id);
    });
});
