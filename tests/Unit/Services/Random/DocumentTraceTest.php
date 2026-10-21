<?php

use App\Services\Random\DocumentTrace;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

test('an NVV alone in its trace has no associated document', function () {
    $trace = DocumentTrace::fromPayload([
        ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV', 'NUDO' => '0001']],
    ]);

    expect($trace->associatedWith(360))->toBeEmpty()
        ->and($trace->firstSettlingDocument())->toBeNull();
});

test('an invoice or a receipt in the trace is associated and settles the NVV', function (string $type) {
    $trace = DocumentTrace::fromPayload([
        ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV']],
        ['maeedo' => ['IDMAEEDO' => '361', 'TIDO' => $type, 'NUDO' => '0002']],
    ]);

    $associated = $trace->associatedWith(360);
    $settling = $trace->firstSettlingDocument();

    expect($associated)->toHaveCount(1)
        ->and($associated->first()['type'])->toBe($type)
        ->and($associated->first()['idmaeedo'])->toBe(361)
        ->and($settling['type'])->toBe($type)
        ->and($settling['maeedo']['NUDO'])->toBe('0002');
})->with(['FCV', 'BLV']);

test('another document type is associated and does not settle the credit', function (string $type) {
    $trace = DocumentTrace::fromPayload([
        ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV']],
        ['maeedo' => ['IDMAEEDO' => 361, 'TIDO' => $type]],
    ]);

    expect($trace->associatedWith(360)->first()['type'])->toBe($type)
        ->and($trace->firstSettlingDocument())->toBeNull();
})->with(['GDV', 'OCC']);

test('another sales note in the trace is associated', function () {
    $trace = DocumentTrace::fromPayload([
        ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV']],
        ['maeedo' => ['IDMAEEDO' => 361, 'TIDO' => 'NVV']],
    ]);

    expect($trace->associatedWith(360))->toHaveCount(1)
        ->and($trace->associatedWith(360)->first()['idmaeedo'])->toBe(361);
});

test('entries without a document header are ignored', function () {
    $trace = DocumentTrace::fromResponse(new Response(new Psr7Response(200, [], json_encode([
        'data' => [
            ['maeddo' => [['IDRST' => 0]]],
            ['maeedo' => ['IDMAEEDO' => 360]],
            ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV']],
        ],
    ]))));

    expect($trace->associatedWith(360))->toBeEmpty();
});
