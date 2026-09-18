<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Strict category association
    |--------------------------------------------------------------------------
    |
    | Value used when the key "category_association_settings" does not exist in
    | siteinfo (the source of truth, manageable from /settings/category-association).
    |
    | Random ERP resolves the superfamily (FMPR) and the family (PFPR) codes of a
    | product independently, so a product can end up filed under a supercategory
    | that is not the parent of its category. With this flag on, such a product
    | stops holding up any node of the category tree and stops showing in the
    | category facets of the product search.
    |
    | Defaults to false: turning it on is a deliberate decision, not a side effect
    | of a deploy.
    |
    */
    'strict_category_association_enabled' => filter_var(
        env('STRICT_CATEGORY_ASSOCIATION_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
