<?php

use App\Exceptions\RandomApiServiceErrorException;
use App\Services\RandomApiService;
use Illuminate\Support\Facades\Http;

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
                    ],
                ],
            ],
            'object' => 'list',
        ], 200),
    ]);

    $service = new RandomApiService;
    $response = $service->getDocumentTrace($idmaeedo);

    expect($response->successful())->toBeTrue()
        ->and($response->json('object'))->toBe('list');
});

it('throws an error when trace request fails', function () {
    $idmaeedo = 999;

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/documentos/traza/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $service = new RandomApiService;

    expect(fn () => $service->getDocumentTrace($idmaeedo))
        ->toThrow(RandomApiServiceErrorException::class);
});

it('sends every expected query parameter when fetching customer entities', function () {
    config([
        'random.business_code' => '01',
        'random.modality' => 'CASAM',
    ]);

    Http::fake([
        '*/web32/entidades*' => Http::response([['IDMAEEN' => 2475, 'KOEN' => '77528378']], 200),
    ]);

    $entities = (new RandomApiService)->getEntidadesUsuarios(100, 2);

    expect($entities)->toBe([['IDMAEEN' => 2475, 'KOEN' => '77528378']]);

    Http::assertSentCount(1);

    $request = Http::recorded()->first()[0];
    parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

    expect($request->method())->toBe('GET')
        ->and(parse_url($request->url(), PHP_URL_PATH))->toEndWith('/web32/entidades')
        ->and($query)->toHaveKeys(['empresa', 'modalidad', 'size', 'page', 'fields'])
        ->and($query['empresa'])->toBe('01')
        ->and($query['modalidad'])->toBe('CASAM')
        ->and($query['size'])->toBe('100')
        ->and($query['page'])->toBe('2')
        ->and(explode(',', $query['fields']))->toEqualCanonicalizing([
            'IDMAEEN', 'KOEN', 'SUEN', 'TIPOSUC', 'TIEN', 'RTEN', 'NOKOEN', 'SIEN', 'EMAIL',
            'EMAILCOMER', 'FOEN', 'DIEN', 'CIEN', 'CMEN', 'CPOSTAL', 'LVEN',
        ]);
});

it('requests the LVEN field so Random returns the customer pricing list', function () {
    Http::fake([
        '*/web32/entidades*' => Http::response([], 200),
    ]);

    (new RandomApiService)->getEntidadesUsuarios();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/web32/entidades')
            && in_array('LVEN', explode(',', $query['fields'] ?? ''), true);
    });
});

it('uses the default page size and page when fetching customer entities', function () {
    Http::fake([
        '*/web32/entidades*' => Http::response([], 200),
    ]);

    (new RandomApiService)->getEntidadesUsuarios();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['size'] === '15' && $query['page'] === '1';
    });
});

it('uses the granted credit and ignores the inflated summary limit', function () {
    config(['random.token' => 'fake_token', 'random.business_code' => '01', 'random.modality' => 'ADMIN']);

    Http::fake([
        ...randomGrantedCreditStub(config('random.url'), '77722167', '1', 50000000),
        '*/gestion/credito/resumen/77722167/1' => Http::response([
            'KOEN' => '77722167',
            'SUEN' => '1',
            'CRSD' => 450000000,
            'CRSDVU' => 1200,
            'CRSDVV' => 300,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
        ], 200),
    ]);

    $credit = (new RandomApiService)->getCreditLine('77722167', '1');

    expect($credit)->toBe([
        'KOEN' => '77722167',
        'SUEN' => '1',
        'CRSD' => 50000000,
        'CRSDVU' => 1200,
        'CRSDVV' => 300,
        'CRSDCU' => 0,
        'CRSDCV' => 0,
    ]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if (! str_contains($request->url(), '/web32/entidades')) {
            return false;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['koen'] === '77722167'
            && $query['fields'] === 'IDMAEEN,KOEN,SUEN,CRTO,CRSD';
    });
});

it('treats a missing credit summary as zero usage when the branch has granted credit', function () {
    config(['random.token' => 'fake_token']);

    Http::fake([
        ...randomGrantedCreditStub(config('random.url'), '77722167', 'L01', 50000000),
        '*/gestion/credito/resumen/*' => Http::response(['message' => 'No se encuentra el recurso'], 404),
    ]);

    expect((new RandomApiService)->getCreditLine('77722167', 'L01'))
        ->toMatchArray([
            'CRSD' => 50000000,
            'CRSDVU' => 0,
            'CRSDVV' => 0,
        ]);
});

it('throws when the summary is missing the used amount', function () {
    config(['random.token' => 'fake_token']);

    Http::fake([
        '*/gestion/credito/resumen/*' => Http::response(['KOEN' => '77722167'], 200),
    ]);

    expect(fn () => (new RandomApiService)->getCreditLine('77722167', '1'))
        ->toThrow(RandomApiServiceErrorException::class);
});

it('can create a document in Random API', function () {
    $payload = [
        'doc' => 'test',
    ];

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/web32/documento*' => Http::response(['message' => 'Document created successfully'], 200),
    ]);

    $service = new RandomApiService;
    $response = $service->createDocument($payload);

    expect($response->successful())->toBeTrue()
        ->and($response->json('message'))->toBe('Document created successfully');
});

it('throws an error when document creation fails', function () {
    $payload = [];

    Http::fake([
        '*/login' => Http::response(['token' => 'fake_token'], 200),
        '*/web32/documento*' => Http::response(['message' => 'Bad Request'], 400),
    ]);

    $service = new RandomApiService;

    expect(fn () => $service->createDocument($payload))
        ->toThrow(RandomApiServiceErrorException::class);
});

it('deletes a document in Random ERP by idmaeedo', function () {
    config([
        'random.url' => 'http://random.test',
        'random.token' => 'token-123',
    ]);

    Http::fake([
        'http://random.test/web32/documento/360' => Http::response(['deleted' => true], 200),
    ]);

    $response = (new RandomApiService)->deleteDocument(360);

    expect($response->successful())->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'http://random.test/web32/documento/360'
            && $request->hasHeader('Authorization', 'Bearer token-123')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('throws when Random ERP rejects the document deletion', function () {
    config([
        'random.url' => 'http://random.test',
        'random.token' => 'token-123',
    ]);

    Http::fake([
        'http://random.test/web32/documento/360' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(fn () => (new RandomApiService)->deleteDocument(360))
        ->toThrow(RandomApiServiceErrorException::class);
});
