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
        'sync-product-images',

        // Address related permissions
        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",

        // Notification related permissions
        "create-notifications",
        
        // Warehouse permissions
        'read-warehouses',
        'manage-warehouses',

        //Cart
        'read-own-cart',
        'delete-cart',
        'create-cart-items',
        'delete-cart-items',
        
        // System configuration permissions
        "update-system-config",

    ],
    'admin' => [
        'read-users',
        'read-admin-users',
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
        'sync-product-images',
        'update-system-config',


        // Address related permissions
        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",

        // Notification related permissions
        "create-notifications",
        
        // Warehouse permissions
        'read-warehouses',
        'manage-warehouses',

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

        'read-customers-export',

        // FAQ related permissions
        "read-all-faqs",
        'read-all-products-export',
        'read-all-categories-export',

        // Warehouse permissions
        'read-warehouses',

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

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",

        // Warehouse permissions
        'read-warehouses',
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

        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
        // Siteinfo authorization
        'read-content-settings',
    ],
];
