<?php

use App\Mail\OrderCancellationMail;
use App\Mail\OrderCompletedMail;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\RandomDocument;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * @return list<string>
 */
function renderedOrderNotificationEmails(Order $order): array
{
    return [
        (new OrderCancellationMail($order))->render(),
        (new OrderConfirmationMail($order))->render(),
        (new OrderCompletedMail($order))->render(),
    ];
}

function attachRandomDocument(Order $order, int $idmaeedo, string $type): void
{
    $document = RandomDocument::query()->create([
        'idmaeedo' => $idmaeedo,
        'type' => $type,
        'document' => ['TIDO' => $type],
    ]);
    $order->randomDocuments()->attach($document->idmaeedo);
}

it('shows the Random sales note number on the three order emails', function () {
    $order = Order::factory()->create();
    attachRandomDocument($order, 360, 'NVV');
    attachRandomDocument($order, 361, 'FCV');

    foreach (renderedOrderNotificationEmails($order->fresh()) as $body) {
        expect($body)->toContain('Número de pedido Random: <strong>360</strong>')
            ->and($body)->not->toContain('Número de pedido Random: <strong>361</strong>');
    }
});

it('uses another Random document when the order has no sales note', function () {
    $order = Order::factory()->create();
    attachRandomDocument($order, 361, 'FCV');

    foreach (renderedOrderNotificationEmails($order->fresh()) as $body) {
        expect($body)->toContain('Número de pedido Random: <strong>361</strong>');
    }
});

it('omits the Random order number when the order has no Random document', function () {
    $order = Order::factory()->create();

    foreach (renderedOrderNotificationEmails($order) as $body) {
        expect($body)->not->toContain('Número de pedido Random:');
    }
});
