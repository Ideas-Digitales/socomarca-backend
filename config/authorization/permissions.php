<?php

return [
    // User management
    'read-users', // [superadmin, admin]
    'read-admin-users', // [superadmin]
    'create-users', // [superadmin, admin]
    'create-admin-users', // [superadmin]
    'update-users', // [superadmin, admin]
    'delete-users', // [superadmin, admin]

    "see-all-clients", // [DEPRECATED]
    "manage-users", // [DEPRECATED]
    "manage-admins", // [DEPRECATED]

    // Own profile management
    'read-own-profile', // [superadmin, admin, supervisor, editor, customer]
    'update-profile', // [superadmin, admin, supervisor, editor]
    'update-own-password', // [superadmin, admin, supervisor, editor, customer]

    // Customer
    'read-customers', // [supervisor]

    // Address related permissions
    'read-all-addresses', // [superadmin, admin]
    'read-own-addresses', // [superadmin, admin, customer]
    'create-addresses', // [superadmin, admin, customer]
    'update-addresses', // [superadmin, admin, customer]
    'delete-addresses', // [superadmin, admin, customer]
    'see-all-addresses', // [superadmin, admin]
    'see-own-addresses', // [superadmin, admin, customer]
    'store-address', // [superadmin, admin, customer]
    'update-address', // [superadmin, admin, customer]
    'delete-address', // [superadmin, admin, customer]

    // FAQ related permissions
    "read-all-faqs",
    "create-faqs",
    "update-faqs",
    "delete-faqs",

    // Favorites permissions
    'read-own-favorites', // [customer]
    'create-favorites', // [customer]
    'delete-favorites', // [customer]
    'read-own-favorites-list', // [customer]
    'create-favorites-list', // [customer]
    'update-favorites-list', // [customer]
    'delete-favorites-list', // [customer]

    // Cart permissions
    "see-own-purchases",
    "see-all-purchases",
    'read-own-cart', // [customer]
    'delete-cart', // [customer]
    'create-cart-items', // [customer]
    'delete-cart-items', // [customer]

    // Order permissions
    'read-own-orders', // [customer]
    'create-orders', // [customer]
    'update-orders', // [customer]

    // Brand permissions
    'read-all-brands', // [superadmin, admin, supervisor, editor, customer]

    // Categories permissions
    'read-all-categories', // [superadmin, admin, supervisor, editor, customer]

    // "manage-categories", // [DEPRECATED]

    // Subcategory permissions
    'read-all-subcategories', // [superadmin, admin, supervisor, editor, customer]


    // FAQ permissions
    'read-all-faqs', // [superadmin, admin, supervisor, editor, customer]
    'create-faqs', // [superadmin, admin, editor]
    'update-faqs', // [superadmin, admin, editor]
    'delete-faqs', // [superadmin, admin, editor]

    "manage-faq", // [DEPRECATED]
    "store-faq", // [DEPRECATED]
    "update-faq", // [DEPRECATED]
    "delete-faq", // [DEPRECATED]

    // Reports permissions
    'read-all-reports', // [superadmin, admin, editor]

    "see-all-reports", // [DEPRECATED]

    // Prices permissions
    'read-all-prices', // [superadmin, admin, supervisor, editor, customer]

    // Products permissions
    'read-all-products', // [superadmin, admin, supervisor, editor, customer]
    'sync-product-images', // [superadmin, admin]

    // Content edition permissions
    'read-content-settings', //[superadmin, admin, editor]
    'update-content-settings', //[superadmin, admin, editor]

    // System settings permissions
    'read-all-system-config', // [superadmin, admin]
    'update-system-config', //[superadmin, admin]

    // Municipalities permissions
    'update-municipalities', // [superadmin, admin]

    // Regions permissions
    'read-all-regions', // [superadmin, admin, supervisor, editor, customer]
    'update-regions', // [superadmin, admin]

    // Permissions
    'read-all-permissions', // [superadmin, admin]

    // Roles permissions
    'read-all-roles', // [superadmin]
    'read-user-roles', // [superadmin, admin]

    // Exportar
    'read-all-products-export',
    'read-all-categories-export',
    'read-customers-export',

    // Payment permissions
    'read-all-payment-methods', // [superadmin, admin]
    'update-payment-methods', // [superadmin, admin]

    // Notification permissions
    'create-notifications', // [superadmin, admin]

    'update-system-config' // [superadmin, admin]
];
