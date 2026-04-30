<?php

use App\Services\RandomApiService;
use Illuminate\Support\Facades\Http;
use App\Exceptions\RandomApiServiceErrorException;

it('can get a document trace from Random API', function () {
    $idmaeedo = 360;

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/documentos/traza/*' => Http::response([
            'data' => [
                [
                    'maeedo' => [
                        'IDMAEEDO' => 360,
                        'NUDO' => '0000140684',
                    ]
                ]
            ],
            'object' => 'list'
        ], 200)
    ]);

    $service = new RandomApiService();
    $response = $service->getDocumentTrace($idmaeedo);

    expect($response->successful())->toBeTrue()
        ->and($response->json('object'))->toBe('list');
});

it('throws an error when trace request fails', function () {
    $idmaeedo = 999;

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/documentos/traza/*' => Http::response(['message' => 'Not Found'], 404)
    ]);

    $service = new RandomApiService();

    expect(fn () => $service->getDocumentTrace($idmaeedo))
        ->toThrow(RandomApiServiceErrorException::class);
});

it('can create a document in Random API', function () {
    $payload = [
        'doc' => 'test'
    ];

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/web32/documento' => Http::response(['message' => 'Document created successfully'], 200)
    ]);

    $service = new RandomApiService();
    $response = $service->createDocument($payload);

    expect($response->successful())->toBeTrue()
        ->and($response->json('message'))->toBe('Document created successfully');
});

it('throws an error when document creation fails', function () {
    $payload = [];

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/web32/documento' => Http::response(['message' => 'Bad Request'], 400)
    ]);

    $service = new RandomApiService();

    expect(fn () => $service->createDocument($payload))
        ->toThrow(RandomApiServiceErrorException::class);
});
