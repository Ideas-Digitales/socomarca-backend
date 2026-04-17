<?php

return [
    'url' => env('RANDOM_ERP_URL', 'http://seguimiento.random.cl:3003'),
    'username' => env('RANDOM_ERP_USERNAME', 'demo@random.cl'),
    'password' => env('RANDOM_ERP_PASSWORD', 'd3m0r4nd0m3RP'),
    'token' => env('RANDOM_ERP_TOKEN', ''),
    'business_code' => env('RANDOM_ERP_BUSINESS_CODE', 'your_default_business_code'),
    'modality' => env('RANDOM_ERP_MODALITY', 'ADMIN'),
    'mock' => [
        'documents' => [
            'fcv' => [
                'enabled' => env('RANDOM_MOCK_DOCS_FCV', false),
                'response' => [
                    'bad' => env('RANDOM_MOCK_DOCS_FCV_RESPONSE_BAD', false)
                ]
            ],
        ],
        'credit' => [
            'branch' => env('RANDOM_MOCK_CREDIT_BRANCH', false)
        ]
    ]
];
