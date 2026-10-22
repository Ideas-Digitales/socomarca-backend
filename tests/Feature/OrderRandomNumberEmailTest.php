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

it('shows the Random folio with its leading zeros on the three order emails', function () {
    $order = Order::factory()->create([
        'random_document_number' => '0000140684',
    ]);
    $document = RandomDocument::query()->create([
        'idmaeedo' => 360,
        'type' => 'NVV',
        'document' => ['TIDO' => 'NVV', 'NUDO' => '0000140684'],
    ]);
    $order->randomDocuments()->attach($document->idmaeedo);

    foreach (renderedOrderNotificationEmails($order->fresh()) as $body) {
        expect($body)->toContain('Número de pedido Random: <strong>0000140684</strong>')
            ->and($body)->not->toContain('Número de pedido Random: <strong>360</strong>');
    }
});

it('omits the Random order number when the order has no folio', function () {
    $order = Order::factory()->create([
        'random_document_number' => null,
    ]);
    $document = RandomDocument::query()->create([
        'idmaeedo' => 360,
        'type' => 'NVV',
        'document' => ['TIDO' => 'NVV'],
    ]);
    $order->randomDocuments()->attach($document->idmaeedo);

    foreach (renderedOrderNotificationEmails($order->fresh()) as $body) {
        expect($body)->not->toContain('Número de pedido Random:');
    }
});
