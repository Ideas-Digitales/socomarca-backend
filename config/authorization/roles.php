<?php

return [
    'superadmin' => [
        // User management permissions
        'read-own-profile',
        'create-users',
        'create-admins',
        'read-users',
        'read-admins',
        'update-users',
        'delete-users',

        "see-own-purchases",
        "see-all-reports",
        "see-all-products",
        "see-all-clients",
        "see-all-purchases",
        "edit-content",
        "edit-products",
        "manage-categories",

        // Address related permissions
        "see-all-addresses",
        "see-own-addresses",
        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",

        // List permissions names
        "see-all-permissions",
    ],
    'admin' => [
        // User management permissions
        'read-users',
        'create-users',
        'update-users',
        'delete-users',

        "see-own-purchases",
        "see-all-reports",
        "see-all-products",
        "see-all-clients",
        "see-all-purchases",
        "edit-content",
        "edit-products",
        "manage-categories",

        // Address related permissions
        "see-all-addresses",
        "see-own-addresses",
        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",

        // List permissions names
        "see-all-permissions",
    ],
    'supervisor' => [
        // Own user permissions
        'read-own-profile',
        'update-profile',
        'update-own-password',

        "see-own-purchases",
        "see-all-reports",
        "see-all-products",
        "see-all-clients",
        "see-all-purchases",

        // FAQ related permissions
        "read-all-faqs",
    ],
    'editor' => [
        // Own user permissions
        'read-own-profile',
        'update-profile',
        'update-own-password',

        "see-own-purchases",
        "see-all-products",
        "edit-content",

        // FAQ related permissions
        "read-all-faqs",
        "create-faqs",
        "update-faqs",
        "delete-faqs",
    ],
    'customer' => [
        // Own user permissions
        'read-own-profile',
        'update-own-password',

        "see-own-purchases",
        "see-own-addresses",
        "store-address",
        "update-address",
        "delete-address",

        // FAQ related permissions
        "read-all-faqs",
    ],
];
