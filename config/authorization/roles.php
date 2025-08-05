<?php

return [
    'superadmin' => [
        'read-users',
        'read-admin-users',
        'create-users',
        'create-admin-users',
        'update-users',
        'delete-users',
        'read-own-profile',
        'update-profile',
        'update-own-password',
        'read-all-addresses',
        'read-own-addresses',
        'create-addresses',
        'update-addresses',
        'delete-addresses',
        'read-all-brands',
        'read-all-categories',
        'read-all-subcategories',
        'read-all-faqs',
        'create-faqs',
        'update-faqs',
        'delete-faqs',
        'read-all-reports',
        'read-all-prices',
        'read-all-products',
        'read-content-settings',
        'update-content-settings',
        'read-all-system-config',
        'update-system-config',
        'update-municipalities',
        'read-all-regions',
        'update-regions',
        'read-all-permissions',
        'read-all-roles',
        'read-user-roles',
        'read-all-payment-methods',
        'update-payment-methods',
        'read-all-products-export',
        'read-all-categories-export',
        'read-customers-export',

        "see-all-addresses", // [DEPRECATED]
        "see-own-addresses", // [DEPRECATED]
        "store-address", // [DEPRECATED]
        "update-address", // [DEPRECATED]
        "delete-address", // [DEPRECATED]
        "manage-categories", // [DEPRECATED]
        "manage-faq", // [DEPRECATED]
        "store-faq", // [DEPRECATED]
        "update-faq", // [DEPRECATED]
        "delete-faq", // [DEPRECATED]
        "see-all-reports", // [DEPRECATED]
        "see-all-products", // [DEPRECATED]
        "edit-products", // [DEPRECATED]
    ],
    'admin' => [
        'read-users',
        'create-users',
        'update-users',
        'delete-users',
        'read-own-profile',
        'update-profile',
        'update-own-password',
        'read-all-addresses',
        'read-own-addresses',
        'create-addresses',
        'update-addresses',
        'delete-addresses',
        'read-content-settings',
        'update-content-settings',
        'read-all-system-config',
        'update-system-config',
        'update-municipalities',
        'update-regions',
        'read-all-permissions',
        'read-user-roles',
        'read-all-payment-methods',
        'update-payment-methods',
        'read-all-brands',
        'read-all-categories',
        'read-all-subcategories',
        'read-all-faqs',
        'create-faqs',
        'update-faqs',
        'delete-faqs',
        'read-all-reports',
        'read-all-prices',
        'read-all-products',
        'read-all-regions',
        'read-all-products-export',
        'read-all-categories-export',
        'read-customers-export',

        "see-all-addresses", // [DEPRECATED]
        "see-own-addresses", // [DEPRECATED]
        "store-address", // [DEPRECATED]
        "update-address", // [DEPRECATED]
        "delete-address", // [DEPRECATED]
        "manage-categories", // [DEPRECATED]
        "manage-faq", // [DEPRECATED]
        "store-faq", // [DEPRECATED]
        "update-faq", // [DEPRECATED]
        "delete-faq", // [DEPRECATED]
        "see-all-reports", // [DEPRECATED]
        "see-all-products", // [DEPRECATED]
        "edit-products", // [DEPRECATED]
    ],
    'supervisor' => [
        'read-own-profile',
        'update-profile',
        'update-own-password',
        'read-customers',
        'read-all-brands',
        'read-all-categories',
        'read-all-subcategories',
        'read-all-faqs',
        'read-all-prices',
        'read-all-products',
        'read-all-regions',
        'read-all-products-export',
        'read-all-categories-export',
        'read-customers-export',
        "see-all-reports", // [DEPRECATED]
        "see-all-products", // [DEPRECATED]

    ],
    'editor' => [
        'read-own-profile',
        'update-profile',
        'update-own-password',
        'read-all-brands',
        'read-all-categories',
        'read-all-subcategories',
        'read-all-faqs',
        'create-faqs',
        'update-faqs',
        'delete-faqs',
        'read-all-reports',
        'read-all-prices',
        'read-all-products',
        'read-content-settings',
        'update-content-settings',
        'read-all-regions',

        "edit-content", // [DEPRECATED]
    ],
    'customer' => [
        'read-own-profile',
        'update-own-password',
        'read-customers',
        'read-own-addresses',
        'create-addresses',
        'update-addresses',
        'delete-addresses',
        'read-own-favorites',
        'create-favorites',
        'delete-favorites',
        'read-own-favorites-list',
        'create-favorites-list',
        'update-favorites-list',
        'delete-favorites-list',
        'read-own-cart',
        'delete-cart',
        'create-cart-items',
        'delete-cart-items',
        'read-own-orders',
        'create-orders',
        'update-orders',
        'read-all-brands',
        'read-all-categories',
        'read-all-subcategories',
        'read-all-faqs',
        'read-all-prices',
        'read-all-products',
        'read-all-regions',

        "see-all-addresses", // [DEPRECATED]
        "see-own-addresses", // [DEPRECATED]
        "store-address", // [DEPRECATED]
        "update-address", // [DEPRECATED]
        "delete-address", // [DEPRECATED]
        "see-all-products", // [DEPRECATED]
    ],
];
