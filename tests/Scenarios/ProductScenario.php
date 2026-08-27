<?php

namespace Tests\Scenarios;

class ProductScenario
{
    public function __construct(
        public array $getResponseStructure
    ) {}

    public static function make(): ProductScenario
    {
        $getResponseStructure = [
            "data" => [
                "*" => [
                    "id",
                    "name",
                    "unit",
                    "price",
                    "stock",
                    "price_list_id",
                    "image",
                    "sku",
                    "is_favorite",
                    "category" => ["id", "name"],
                    "subcategory" => ["id", "name"],
                    "brand" => ["id", "name"],
                ],
            ],
            "extra" => ["supercategories", "categories", "subcategories", "brands"],
            "meta",
            "filters" => ["min_price", "max_price"],
        ];

        return new ProductScenario($getResponseStructure);
    }
}
