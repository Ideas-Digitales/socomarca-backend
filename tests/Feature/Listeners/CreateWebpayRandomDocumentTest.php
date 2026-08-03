<?php

use App\Events\WebpayPaymentAuthorized;
use App\Models\RandomDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Scenarios\WebpayRandomDocumentScenario;

it('implements ShouldQueue', function () {
    expect(WebpayRandomDocumentScenario::makeListener())->toBeInstanceOf(ShouldQueue::class);
});

it('creates the random document and attaches it to the order on success', function () {
    $scenario = WebpayRandomDocumentScenario::make();

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento*" => Http::response(
            [
                'numero' => '0000000099',
                'idmaeedo' => 321,
            ],
            200,
        ),
    ]);

    WebpayRandomDocumentScenario::makeListener()->handle(
        new WebpayPaymentAuthorized($scenario->order, $scenario->payment)
    );

    expect($scenario->order->fresh()->random_document_number)->toBe('0000000099');
    expect($scenario->order->randomDocuments()->count())->toBe(1);
    expect($scenario->order->randomDocuments()->first()->idmaeedo)->toBe(321);
});

it('logs and leaves the order untouched on a Random ERP business error', function () {
    $scenario = WebpayRandomDocumentScenario::make();

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento*" => Http::response(
            [
                'message' => 'invalid data',
                'errorId' => 'aBcD1234',
            ],
            200,
        ),
    ]);

    Log::shouldReceive('error')->once();

    WebpayRandomDocumentScenario::makeListener()->handle(
        new WebpayPaymentAuthorized($scenario->order, $scenario->payment)
    );

    expect($scenario->order->fresh()->random_document_number)->toBeNull();
    expect($scenario->order->fresh()->status)->toBe('completed');
});

it('logs and swallows a thrown exception without failing the order', function () {
    $scenario = WebpayRandomDocumentScenario::make();

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento*" => Http::response(
            ['message' => 'boom'],
            500,
        ),
    ]);

    Log::shouldReceive('error')->atLeast()->once();

    WebpayRandomDocumentScenario::makeListener()->handle(
        new WebpayPaymentAuthorized($scenario->order, $scenario->payment)
    );

    expect($scenario->order->fresh()->random_document_number)->toBeNull();
    expect($scenario->order->fresh()->status)->toBe('completed');
});

it('skips creating a document when the order already has one attached', function () {
    $scenario = WebpayRandomDocumentScenario::make();

    $randomDocument = RandomDocument::create([
        'idmaeedo' => 555,
        'type' => 'NVV',
        'document' => ['numero' => '0000000001'],
    ]);
    $scenario->order->randomDocuments()->attach($randomDocument->idmaeedo);

    Http::fake();

    WebpayRandomDocumentScenario::makeListener()->handle(
        new WebpayPaymentAuthorized($scenario->order, $scenario->payment)
    );

    Http::assertNothingSent();
});
