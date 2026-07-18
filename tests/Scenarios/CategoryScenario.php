<?php

namespace Tests\Scenarios;

use App\Models\User;

class CategoryScenario
{
    public function __construct(
        public User $user,
    ) {}

    public array $listJsonStructure = [
        "*" => [
            "id",
            "name",
            "code",
            "level",
            "key",
            "products_count",
            "categories" => [
                "*" => [
                    "id",
                    "name",
                    "code",
                    "level",
                    "key",
                    "products_count",
                    "subcategories" => [
                        "*" => [
                            "id",
                            "name",
                            "code",
                            "level",
                            "key",
                            "products_count",
                        ],
                    ],
                ],
            ],
        ],
    ];

    public static function make(): CategoryScenario
    {
        $user = User::factory()->create();
        $user->givePermissionTo("read-all-categories");
        $user->update(["prices_lists" => [getPriceListCode()]]);

        return new CategoryScenario($user);
    }
}
