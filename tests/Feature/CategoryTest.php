<?php

use App\Models\Category;
use App\Models\User;

beforeEach(function ()
{
    $this->user = createUser();
    $this->category = createCategory();

    $this->categoryListJsonStructure = [
        'data' => [
            [
                'id',
                'name',
                'description',
                'code',
                'level',
                'key',
                'subcategories_count',
                'products_count',
                'created_at',
                'updated_at',
            ],
        ],
        'meta' => [
            'current_page',
            'from',
            'last_page',
            'path',
            'per_page',
            'to',
            'total',
            'links' => [
                ['url', 'label', 'active']
            ],
        ]
    ];
});

describe('Category API', function () {
    it('should require authentication for index', function () {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get(route('categories.index'));
        $response->assertStatus(401);
    });

    it('should return categories with correct structure', function () {
        $this->user->givePermissionTo('read-all-categories');
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(route('categories.index'));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'name',
                        'description',
                        'code',
                        'level',
                        'key',
                        'subcategories_count',
                        'products_count',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    });

    it('should return 404 for non-existent category', function () {
        $this->user->givePermissionTo('read-all-categories');
        $id = $this->category->id;
        Category::truncate();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->get(route('categories.show', ['category' => $id]));

        $response->assertStatus(404);
    });

    it('should require authentication for category search', function () {
        $this->user->givePermissionTo('read-all-categories');
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson(route('categories.search'));
        $response->assertStatus(401);
    });

    it('should return correct structure for category search', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();
        Category::factory()->count(5)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson(route('categories.search'));

        expect($response->json('data'))->toHaveCount(5);
        $response
            ->assertStatus(200)
            ->assertJsonStructure($this->categoryListJsonStructure);
    });

    it('should filter categories by exact name', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        Category::factory()->create(['name' => 'Dairy and Derivatives']);
        Category::factory()->create(['name' => 'Drinks']);
        Category::factory()->create(['name' => 'Meats and Fish']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'name',
                        'operator' => '=',
                        'value' => 'Dairy and Derivatives',
                    ]
                ]
            ]);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['name'])->toBe('Dairy and Derivatives');
        $response->assertStatus(200);
    });

    it('should filter categories by partial name', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        Category::factory()->create(['name' => 'Dairy Products']);
        Category::factory()->create(['name' => 'Lactose Free Dairy']);
        Category::factory()->create(['name' => 'Drinks']);

        $response = $this->actingAs($this->user, 'sanctum')
        ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%dairy%',
                    ]
                ]
            ]);

        expect($response->json('data'))->toHaveCount(2);
        foreach ($response->json('data') as $category) {
            expect(stripos($category['name'], 'dairy'))->not->toBeFalse();
        }
        $response->assertStatus(200);
    });

    it('should filter categories by description', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        Category::factory()->create(['name' => 'Dairy', 'description' => 'Dairy products and derivatives']);
        Category::factory()->create(['name' => 'Drinks', 'description' => 'Non-alcoholic drinks']);
        Category::factory()->create(['name' => 'Meats', 'description' => 'Red and white meats']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'description',
                        'operator' => 'ILIKE',
                        'value' => '%dairy%',
                    ]
                ]
            ]);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toMatch('/dairy/i');
        $response->assertStatus(200);
    });

    it('should sort categories by name', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        Category::factory()->create(['name' => 'Zebra']);
        Category::factory()->create(['name' => 'Alpha']);
        Category::factory()->create(['name' => 'Beta']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%',
                        'sort' => 'ASC'
                    ]
                ]
            ]);

        $data = $response->json('data');
        expect($data)->toHaveCount(3);
        expect($data[0]['name'])->toBe('Alpha');
        expect($data[1]['name'])->toBe('Beta');
        expect($data[2]['name'])->toBe('Zebra');
        $response->assertStatus(200);
    });

    it('should filter categories by level', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        Category::factory()->create(['level' => 1]);
        Category::factory()->create(['level' => 2]);
        Category::factory()->create(['level' => 1]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'level',
                        'operator' => '=',
                        'value' => 1,
                    ]
                ]
            ]);

        expect($response->json('data'))->toHaveCount(2);
        foreach ($response->json('data') as $category) {
            expect($category['level'])->toBe(1);
        }
        $response->assertStatus(200);
    });

    it('should sort categories by id ascending and descending', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        $catA = Category::factory()->create(['name' => 'First']);
        $catB = Category::factory()->create(['name' => 'Second']);
        $catC = Category::factory()->create(['name' => 'Third']);

        // Ascending order
        $responseAsc = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'sort' => 'id',
                'sort_direction' => 'asc'
            ]);
        $idsAsc = array_column($responseAsc->json('data'), 'id');
        expect($idsAsc)->toBe([min($catA->id, $catB->id, $catC->id), ...array_diff([$catA->id, $catB->id, $catC->id], [min($catA->id, $catB->id, $catC->id), max($catA->id, $catB->id, $catC->id)]), max($catA->id, $catB->id, $catC->id)]);

        // Descending order
        $responseDesc = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/categories/search', [
                'sort' => 'id',
                'sort_direction' => 'desc'
            ]);
        $idsDesc = array_column($responseDesc->json('data'), 'id');
        expect($idsDesc)->toBe([max($catA->id, $catB->id, $catC->id), ...array_diff([$catA->id, $catB->id, $catC->id], [min($catA->id, $catB->id, $catC->id), max($catA->id, $catB->id, $catC->id)]), min($catA->id, $catB->id, $catC->id)]);
    });

    it('should filter and sort categories by name and id', function () {
        $this->user->givePermissionTo('read-all-categories');
        Category::truncate();

        $catA = Category::factory()->create(['name' => 'Food']);
        $catB = Category::factory()->create(['name' => 'Drinks']);
        $catC = Category::factory()->create(['name' => 'Meats']);

        // Filter by partial name and sort by id descending
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('categories.search'), [
                'filters' => [
                    [
                        'field' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%a%',
                    ]
                ],
                'sort' => 'id',
                'sort_direction' => 'desc'
            ]);

        $data = $response->json('data');
        $expected = collect([$catA, $catB, $catC])
            ->filter(fn($cat) => stripos($cat->name, 'a') !== false)
            ->sortByDesc('id')
            ->pluck('id')
            ->values()
            ->all();

        $ids = array_column($data, 'id');
        expect($ids)->toBe($expected);
        $response->assertStatus(200);
    });
});