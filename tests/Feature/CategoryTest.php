<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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
        // Super1: enabled, has products through cat1 and cat2
        $super1 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $cat2 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub2 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub3 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat2->id, 'enabled' => true]);

        // Products for categories (level 2) - matches real data pattern
        for ($i = 0; $i < 2; $i++) {
            Product::create(['name' => "Product Cat1 {$i}", 'supercategory_id' => $super1->id, 'category_id' => $cat1->id, 'sku' => "SKU-CAT1-{$i}", 'status' => true]);
            Product::create(['name' => "Product Cat2 {$i}", 'supercategory_id' => $super1->id, 'category_id' => $cat2->id, 'sku' => "SKU-CAT2-{$i}", 'status' => true]);
        }

        // Super2: disabled, should not appear
        $super2 = Category::factory()->create(['level' => 1, 'enabled' => false]);
        $cat3 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super2->id, 'enabled' => true]);
        $sub4 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat3->id, 'enabled' => true]);
        for ($i = 0; $i < 2; $i++) {
            Product::create(['name' => "Product Cat3 {$i}", 'category_id' => $cat3->id, 'sku' => "SKU-CAT3-{$i}", 'status' => true]);
        }

        // Super3: enabled but category is disabled, should not appear
        $super3 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat4 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super3->id, 'enabled' => false]);
        $sub5 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat4->id, 'enabled' => true]);
        for ($i = 0; $i < 2; $i++) {
            Product::create(['name' => "Product Cat4 {$i}", 'category_id' => $cat4->id, 'sku' => "SKU-CAT4-{$i}", 'status' => true]);
        }

        // Super4: enabled, has products through cat5
        $super4 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat5 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super4->id, 'enabled' => true]);
        $sub6 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => false]);
        $sub7 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => true]);
        for ($i = 0; $i < 2; $i++) {
            Product::create(['name' => "Product Cat5 {$i}", 'supercategory_id' => $super4->id, 'category_id' => $cat5->id, 'sku' => "SKU-CAT5-{$i}", 'status' => true]);
        }

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

        // Should only return enabled supercategories with products (super1, super4)
        // super2 is disabled, super3's only category is disabled
        expect($data)->toHaveCount(2);

        $supers = collect($data)->keyBy('name');

        // Validate Super1 hierarchy
        expect($supers->get($super1->name)['id'])->toBe($super1->id);
        expect($supers->get($super1->name)['level'])->toBe(1);
        expect($supers->get($super1->name)['categories'])->toHaveCount(2);

        $super1Cats = collect($supers->get($super1->name)['categories'])->keyBy('name');
        expect($super1Cats->get($cat1->name)['id'])->toBe($cat1->id);
        expect($super1Cats->get($cat1->name)['level'])->toBe(2);
        expect($super1Cats->get($cat1->name)['products_count'])->toBe(2);

        expect($super1Cats->get($cat2->name)['id'])->toBe($cat2->id);
        expect($super1Cats->get($cat2->name)['products_count'])->toBe(2);

        // Validate Super4
        expect($supers->get($super4->name)['id'])->toBe($super4->id);
        expect($supers->get($super4->name)['categories'])->toHaveCount(1);

        $super4Cats = collect($supers->get($super4->name)['categories'])->keyBy('name');
        expect($super4Cats->get($cat5->name)['id'])->toBe($cat5->id);
        expect($super4Cats->get($cat5->name)['products_count'])->toBe(2);

        // Verify disabled supercategory (super2) is not in response
        $super2InResponse = collect($data)->firstWhere('id', $super2->id);
        expect($super2InResponse)->toBeNull();

        // Verify super3 is not in response because its category is disabled
        $super3InResponse = collect($data)->firstWhere('id', $super3->id);
        expect($super3InResponse)->toBeNull();
    });

    it('should return categories with associated products only', function () {
        // Super1: has products directly via supercategory_id
        $super1 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $cat2 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub2 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        $sub3 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat2->id, 'enabled' => true]);

        // Products directly on super1 (via supercategory_id)
        for ($i = 0; $i < 3; $i++) {
            Product::create(['name' => "Product Super1 {$i}", 'supercategory_id' => $super1->id, 'sku' => "SKU-SUPER1-{$i}", 'status' => true]);
        }
        // Products on cat2 (via category_id)
        for ($i = 0; $i < 2; $i++) {
            Product::create(['name' => "Product Cat2 {$i}", 'category_id' => $cat2->id, 'sku' => "SKU-CAT2-{$i}", 'status' => true]);
        }

        // Super2: disabled, should not appear even with products
        $super2 = Category::factory()->create(['level' => 1, 'enabled' => false]);
        $cat3 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super2->id, 'enabled' => true]);
        for ($i = 0; $i < 5; $i++) {
            Product::create(['name' => "Product Cat3 {$i}", 'category_id' => $cat3->id, 'sku' => "SKU-CAT3-{$i}", 'status' => true]);
        }

        // Super3: enabled but NO products at any level, should not appear
        $super3 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat4 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super3->id, 'enabled' => true]);
        $sub5 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat4->id, 'enabled' => true]);

        // Super4: has products directly via supercategory_id
        $super4 = Category::factory()->create(['level' => 1, 'enabled' => true]);
        $cat5 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super4->id, 'enabled' => true]);
        $sub6 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => true]);
        $sub7 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat5->id, 'enabled' => true]);
        // Products directly on super4
        for ($i = 0; $i < 4; $i++) {
            Product::create(['name' => "Product Super4 {$i}", 'supercategory_id' => $super4->id, 'sku' => "SKU-SUPER4-{$i}", 'status' => true]);
        }

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

        // Should only return supercategories with products (super1, super4)
        expect($data)->toHaveCount(2);

        $supers = collect($data)->keyBy('name');

        // Validate Super1
        expect($supers->get($super1->name)['id'])->toBe($super1->id);
        expect($supers->get($super1->name)['level'])->toBe(1);
        expect($supers->get($super1->name)['products_count'])->toBe(3);

        $super1Cats = collect($supers->get($super1->name)['categories'])->keyBy('name');

        // cat1 has no products, should not appear
        expect($super1Cats->has($cat1->name))->toBeFalse();

        // cat2 has products, should appear
        expect($super1Cats->get($cat2->name)['id'])->toBe($cat2->id);
        expect($super1Cats->get($cat2->name)['products_count'])->toBe(2);

        // Validate Super4
        expect($supers->get($super4->name)['id'])->toBe($super4->id);
        expect($supers->get($super4->name)['products_count'])->toBe(4);
        expect($supers->get($super4->name)['categories'])->toHaveCount(0);

        // Verify disabled supercategory (super2) is not in response
        $super2InResponse = collect($data)->firstWhere('id', $super2->id);
        expect($super2InResponse)->toBeNull();

        // Verify supercategory without products (super3) is not in response
        $super3InResponse = collect($data)->firstWhere('id', $super3->id);
        expect($super3InResponse)->toBeNull();
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

        // Create products for all categories (level 2) - matches real data pattern
        $cats = [$cat1, $cat2, $cat3, $cat4];
        $supers = [$super1, $super2];
        foreach ($supers as $super) {
            foreach ($cats as $idx => $cat) {
                for ($i = 0; $i < 2; $i++) {
                    Product::create(['name' => "Product Cat{$idx} {$i}", 'supercategory_id' => $super->id, 'category_id' => $cat->id, 'sku' => "SKU-CAT{$idx}-{$i}", 'status' => true]);
                }
            }
        }

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
        expect($super1Cats->get($cat1->name)['products_count'])->toBe(4);

        expect($super1Cats->get($cat2->name)['id'])->toBe($cat2->id);
        expect($super1Cats->get($cat2->name)['level'])->toBe(2);
        expect($super1Cats->get($cat2->name)['products_count'])->toBe(4);

        // Validate Super2 hierarchy
        expect($supers->get($super2->name)['id'])->toBe($super2->id);
        expect($supers->get($super2->name)['level'])->toBe(1);
        expect($supers->get($super2->name)['categories'])->toHaveCount(2);

        $super2Cats = collect($supers->get($super2->name)['categories'])->keyBy('name');
        expect($super2Cats->get($cat3->name)['id'])->toBe($cat3->id);
        expect($super2Cats->get($cat3->name)['level'])->toBe(2);
        expect($super2Cats->get($cat3->name)['products_count'])->toBe(4);

        expect($super2Cats->get($cat4->name)['id'])->toBe($cat4->id);
        expect($super2Cats->get($cat4->name)['level'])->toBe(2);
        expect($super2Cats->get($cat4->name)['products_count'])->toBe(4);
    });


    it('should filter categories by name', function () {
        $super1 = Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS', 'code' => '0001', 'key' => '0001']);
        $cat1 = Category::factory()->create(['level' => 2, 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        Product::create(['name' => 'Product Cat1', 'supercategory_id' => $super1->id, 'category_id' => $cat1->id, 'sku' => 'SKU-CAT1', 'status' => true]);

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
        expect($data[0]['categories'][0]['products_count'])->toBe(1);
    });


    it('should filter categories by name without disabled categories', function () {
        $super1 = Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS', 'code' => '0001', 'key' => '0001', 'enabled' => true]);
        $cat1 = Category::factory()->create(['level' => 2, 'name' => 'Categoría Congelados', 'parent_category_id' => $super1->id, 'enabled' => true]);
        $sub1 = Category::factory()->create(['level' => 3, 'parent_category_id' => $cat1->id, 'enabled' => true]);
        Product::create([
            'name' => 'Product Cat1',
            'supercategory_id' => $super1->id,
            'category_id' => $cat1->id,
            'subcategory_id' => $sub1->id,
            'sku' => 'SKU-CAT1',
            'status' => true
        ]);

        Category::factory()->create(['level' => 1, 'name' => 'REFRIGERADOS', 'code' => '0002', 'key' => '0002']);
        Category::factory()->create(['level' => 1, 'name' => 'CONGELADOS 2', 'code' => '0003', 'key' => '0003', 'enabled' => false]);

        // $this->actingAs($this->user, 'sanctum')
        //     ->getJson(route('categories.index'))->ddJson();
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
        expect($data[0]['categories'][0]['products_count'])->toBe(1);
    });
});
