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
 * Credit order whose NVV is still waiting for an FCV invoice.
 */
function makeAwaitingInvoiceOrder(int $nvvId = 360): array
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
        'generate_random_doc_type' => PaymentDocumentType::INVOICE,
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

describe('cancel order waiting for an invoice', function () {
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

    it('rejects orders that are not waiting for an FCV invoice', function (string $setup, string $message) {
        [, $order, $payment] = makeAwaitingInvoiceOrder();

        match ($setup) {
            'invoiced' => tap($order, function (Order $order) {
                $fcv = RandomDocument::query()->create([
                    'idmaeedo' => 361,
                    'type' => 'FCV',
                    'document' => ['TIDO' => 'FCV'],
                ]);
                $order->randomDocuments()->attach($fcv->idmaeedo);
            }),
            'charged' => $payment->update(['status' => PaymentStatus::COMPLETED]),
            'webpay' => $payment->update([
                'payment_method_id' => PaymentMethod::query()->where('code', 'transbank')->value('id'),
            ]),
            'receipt' => $payment->update([
                'generate_random_doc_type' => PaymentDocumentType::RECEIPT,
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
        'charged' => ['charged', 'The order cannot be canceled because it has already been charged.'],
        'webpay' => ['webpay', 'The order cannot be canceled because it has already been charged.'],
        'receipt' => ['receipt', 'Only orders waiting for an invoice (FCV) can be canceled.'],
        'without nvv' => ['without nvv', 'Only orders waiting for an invoice (FCV) can be canceled.'],
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
    })->with([
        'invoiced' => [['NVV', 'FCV'], 'The order cannot be canceled because it already has an invoice (FCV).'],
        'dispatched' => [['NVV', 'GDV'], 'The order cannot be canceled because it has already been dispatched.'],
        'other document' => [['NVV', 'BLV'], 'Only orders waiting for an invoice (FCV) can be canceled.'],
    ]);

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

        app()->setLocale('es');
        postJson(route('orders.cancel'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'El número de orden es obligatorio.');
    });
});
