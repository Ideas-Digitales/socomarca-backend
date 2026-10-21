<?php

use App\Enums\PaymentDocumentType;
use App\Enums\PaymentStatus;
use App\Models\CreditLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RandomDocument;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

/**
 * Credit order whose NVV is still waiting for an invoice (FCV) or a receipt (BLV).
 */
function makeAwaitingInvoiceOrder(int $nvvId = 360, string $documentType = PaymentDocumentType::INVOICE): array
{
    $buyer = User::factory()->create();
    $order = Order::query()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'subtotal' => 1000,
        'vat' => 19,
        'total' => 1190,
        'shipping_cost' => 0,
        'amount' => 1190,
        'status' => 'completed',
        'order_meta' => [],
    ]);

    $nvv = RandomDocument::query()->create([
        'idmaeedo' => $nvvId,
        'type' => 'NVV',
        'document' => ['TIDO' => 'NVV'],
    ]);
    $order->randomDocuments()->attach($nvv->idmaeedo);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'payment_method_id' => PaymentMethod::query()->where('code', 'random_credit')->value('id'),
        'auth_code' => 'auth-'.$nvvId,
        'amount' => $order->amount,
        'response_status' => 'AUTHORIZED',
        'response_message' => ['message' => 'Aprobado'],
        'token' => 'token-'.$nvvId,
        'paid_at' => now(),
        'status' => PaymentStatus::PROCESSING,
        'generate_random_doc_type' => $documentType,
    ]);

    return [$buyer, $order, $payment];
}

/**
 * @param  list<string>  $traceTypes
 */
function fakeAwaitingInvoiceRandom(int $nvvId, array $traceTypes = ['NVV'], ?int $deleteStatus = null): void
{
    $fakes = [
        "*/documentos/traza/{$nvvId}" => Http::response([
            'data' => collect($traceTypes)->values()->map(fn (string $type, int $index) => [
                'maeedo' => ['IDMAEEDO' => $nvvId + $index, 'TIDO' => $type],
            ])->all(),
        ], 200),
    ];

    if ($deleteStatus !== null) {
        $fakes["*/web32/documento/{$nvvId}"] = Http::response(['deleted' => true], $deleteStatus);
    }

    Http::fake($fakes);
}

function actAsRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user, ['api-access']);

    return $user;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

describe('cancel order waiting for an invoice or a receipt', function () {
    it('cancels the order for an administrative role', function (string $role) {
        [$buyer, $order, $payment] = makeAwaitingInvoiceOrder();
        $creditLine = CreditLine::factory()->create([
            'user_id' => $buyer->id,
            'is_blocked' => true,
            'blocking_payment_id' => $payment->id,
        ]);
        $otherLine = CreditLine::factory()->create([
            'user_id' => $buyer->id,
            'branch_code' => 'OTHER',
            'is_blocked' => true,
            'blocking_payment_id' => null,
        ]);
        fakeAwaitingInvoiceRandom(360, ['NVV'], 200);
        actAsRole($role);

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertOk()
            ->assertJsonPath('message', 'The order was canceled.')
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.payments.0.status', PaymentStatus::CANCELED);

        expect($order->refresh()->status)->toBe('canceled')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::CANCELED)
            ->and($creditLine->refresh()->isBlocked())->toBeFalse()
            ->and($creditLine->blocking_payment_id)->toBeNull()
            ->and($otherLine->refresh()->isBlocked())->toBeTrue();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/web32/documento/360'));
    })->with(['superadmin', 'admin']);

    it('cancels a credit order waiting for a receipt', function () {
        [$buyer, $order, $payment] = makeAwaitingInvoiceOrder(documentType: PaymentDocumentType::RECEIPT);
        $creditLine = CreditLine::factory()->create([
            'user_id' => $buyer->id,
            'is_blocked' => true,
            'blocking_payment_id' => $payment->id,
        ]);
        fakeAwaitingInvoiceRandom(360, ['NVV'], 200);
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.payments.0.status', PaymentStatus::CANCELED);

        expect($order->refresh()->status)->toBe('canceled')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::CANCELED)
            ->and($creditLine->refresh()->isBlocked())->toBeFalse()
            ->and($creditLine->blocking_payment_id)->toBeNull();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/web32/documento/360'));
    });

    it('rejects a second cancellation without calling Random again', function () {
        [, $order] = makeAwaitingInvoiceOrder();
        fakeAwaitingInvoiceRandom(360, ['NVV'], 200);
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'The order is already canceled.');

        $deletes = collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->method() === 'DELETE');

        expect($deletes)->toHaveCount(1);
    });

    it('does not change the order when Random ERP rejects the deletion', function () {
        [$buyer, $order, $payment] = makeAwaitingInvoiceOrder();
        $creditLine = CreditLine::factory()->create([
            'user_id' => $buyer->id,
            'is_blocked' => true,
            'blocking_payment_id' => $payment->id,
        ]);
        fakeAwaitingInvoiceRandom(360, ['NVV'], 500);
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Random ERP document deletion failed');

        expect($order->refresh()->status)->toBe('completed')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::PROCESSING)
            ->and($creditLine->refresh()->isBlocked())->toBeTrue();
    });

    it('requires both read-all-orders and cancel-orders', function (string $role) {
        actAsRole($role);

        postJson(route('orders.cancel'), ['order_number' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission.');
    })->with(['customer', 'supervisor', 'editor', 'developer']);

    it('forbids a user that has only one of the two permissions', function (array $permissions) {
        $user = createUserWithPermissions($permissions);
        Sanctum::actingAs($user, ['api-access']);

        postJson(route('orders.cancel'), ['order_number' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission.');
    })->with([
        'read only' => [['read-all-orders']],
        'cancel only' => [['cancel-orders']],
    ]);

    it('requires authentication', function () {
        postJson(route('orders.cancel'), ['order_number' => 1])->assertUnauthorized();
    });

    it('validates the order number', function (array $payload, string $message) {
        actAsRole('admin');

        postJson(route('orders.cancel'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', $message);
    })->with([
        'missing' => [[], 'The order number is required.'],
        'not an integer' => [['order_number' => 'abc'], 'The order number must be an integer.'],
        'unknown' => [['order_number' => 999999], 'The order number does not exist.'],
    ]);

    it('rejects orders that are not waiting for a Random document', function (string $setup, string $message) {
        [, $order, $payment] = makeAwaitingInvoiceOrder();

        match ($setup) {
            'invoiced', 'receipt issued' => tap($order, function (Order $order) use ($setup) {
                $type = $setup === 'invoiced' ? 'FCV' : 'BLV';
                $document = RandomDocument::query()->create([
                    'idmaeedo' => 361,
                    'type' => $type,
                    'document' => ['TIDO' => $type],
                ]);
                $order->randomDocuments()->attach($document->idmaeedo);
            }),
            'charged' => $payment->update(['status' => PaymentStatus::COMPLETED]),
            'webpay' => $payment->update([
                'payment_method_id' => PaymentMethod::query()->where('code', 'transbank')->value('id'),
            ]),
            'without document type' => $payment->update([
                'generate_random_doc_type' => null,
            ]),
            'without nvv' => $order->randomDocuments()->detach(),
            'canceled' => $order->update(['status' => 'canceled']),
        };

        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', $message);

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::CANCELED);
    })->with([
        'invoiced' => ['invoiced', 'The order cannot be canceled because it already has an invoice (FCV).'],
        'receipt issued' => ['receipt issued', 'The order cannot be canceled because it already has a receipt (BLV).'],
        'charged' => ['charged', 'The order cannot be canceled because it has already been charged.'],
        'webpay' => ['webpay', 'The order cannot be canceled because it has already been charged.'],
        'without document type' => ['without document type', 'Only orders waiting for a Random document (receipt or invoice) can be canceled.'],
        'without nvv' => ['without nvv', 'Only orders waiting for a Random document (receipt or invoice) can be canceled.'],
        'canceled' => ['canceled', 'The order is already canceled.'],
    ]);

    it('rejects the order when the Random trace shows it is no longer waiting', function (array $trace, string $message) {
        [, $order, $payment] = makeAwaitingInvoiceOrder();
        fakeAwaitingInvoiceRandom(360, $trace);
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', $message);

        expect($order->refresh()->status)->toBe('completed')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::PROCESSING);

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    })->with([
        'invoiced' => [['NVV', 'FCV'], 'The order cannot be canceled because it already has an invoice (FCV).'],
        'receipt issued' => [['NVV', 'BLV'], 'The order cannot be canceled because it already has a receipt (BLV).'],
        'dispatched' => [['NVV', 'GDV'], 'The order cannot be canceled because it has already been dispatched.'],
        'other document' => [['NVV', 'OCC'], 'Only orders waiting for a Random document (receipt or invoice) can be canceled.'],
    ]);

    it('does not delete the NVV when an invoice appears in the trace after the first check', function () {
        [, $order, $payment] = makeAwaitingInvoiceOrder();
        $traceCalls = 0;
        Http::fake(function ($request) use (&$traceCalls) {
            if (str_contains($request->url(), '/documentos/traza/')) {
                $traceCalls++;
                $documents = [
                    ['maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV']],
                ];

                if ($traceCalls >= 3) {
                    $documents[] = ['maeedo' => ['IDMAEEDO' => 361, 'TIDO' => 'FCV']];
                }

                return Http::response(['data' => $documents], 200);
            }

            return Http::response(['deleted' => true], 200);
        });
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'The order cannot be canceled because it already has an invoice (FCV).');

        expect($order->refresh()->status)->toBe('completed')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::PROCESSING)
            ->and($traceCalls)->toBeGreaterThanOrEqual(3);

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    });

    it('rejects the order when the trace cannot be confirmed', function () {
        [, $order] = makeAwaitingInvoiceOrder();
        Http::fake([
            '*/documentos/traza/360' => Http::response(['message' => 'error'], 500),
        ]);
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.order_number.0',
                'The order could not be canceled because its status in Random ERP could not be confirmed.',
            );
    });

    it('returns the validation messages in Spanish', function () {
        [, $order] = makeAwaitingInvoiceOrder();
        $fcv = RandomDocument::query()->create([
            'idmaeedo' => 361,
            'type' => 'FCV',
            'document' => ['TIDO' => 'FCV'],
        ]);
        $order->randomDocuments()->attach($fcv->idmaeedo);
        app()->setLocale('es');
        actAsRole('admin');

        postJson(route('orders.cancel'), ['order_number' => $order->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'La orden no se puede cancelar porque ya tiene una factura (FCV).');

        [, $receiptOrder] = makeAwaitingInvoiceOrder(460);
        $blv = RandomDocument::query()->create([
            'idmaeedo' => 362,
            'type' => 'BLV',
            'document' => ['TIDO' => 'BLV'],
        ]);
        $receiptOrder->randomDocuments()->attach($blv->idmaeedo);

        postJson(route('orders.cancel'), ['order_number' => $receiptOrder->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'La orden no se puede cancelar porque ya tiene una boleta (BLV).');

        app()->setLocale('es');
        postJson(route('orders.cancel'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'El número de orden es obligatorio.');
    });
});
