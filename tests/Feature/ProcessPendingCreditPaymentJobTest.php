<?php

use App\Jobs\ProcessPendingCreditPaymentJob;
use App\Models\CreditLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RandomDocument;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * Credit payment of the user, with the NVV `$nvvId` attached to its order when given.
 */
function createCreditPaymentWithNvv(User $user, ?int $nvvId = null, string $status = 'processing'): Payment
{
    $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'random_credit'], ['name' => 'Random', 'active' => true]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    if ($nvvId !== null) {
        $nvv = RandomDocument::create(['idmaeedo' => $nvvId, 'type' => 'NVV', 'document' => ['TIDO' => 'NVV']]);
        $order->randomDocuments()->attach($nvv->idmaeedo);
    }

    return Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'status' => $status,
    ]);
}

function createBlockedCreditLine(User $user, ?Payment $blockingPayment): CreditLine
{
    return CreditLine::factory()->create([
        'user_id' => $user->id,
        'branch_code' => 'CM',
        'is_blocked' => true,
        'blocking_payment_id' => $blockingPayment?->id,
    ]);
}

/**
 * Fake RAPI: `$traces` maps an NVV idmaeedo to the document types in its trace.
 */
function fakeRandomCreditApi(array $traces = [], array $creditState = ['CRSD' => 2000, 'CRSDVU' => 100]): void
{
    $baseUrl = config('random.url');
    $fakes = [
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response(
            ['KOEN' => '12345678-9', 'SUEN' => 'CM', 'CRSDVV' => 0, 'CRSDCU' => 0, 'CRSDCV' => 0, ...$creditState],
            200,
        ),
    ];

    foreach ($traces as $nvvId => $types) {
        $fakes["{$baseUrl}/documentos/traza/{$nvvId}"] = Http::response([
            'data' => collect($types)->map(fn (string $type, int $index) => [
                'maeedo' => ['IDMAEEDO' => $nvvId + $index, 'TIDO' => $type],
            ])->all(),
        ], 200);
    }

    Http::fake($fakes);
}

function runPendingCreditPaymentJob(CreditLine $creditLine): void
{
    (new ProcessPendingCreditPaymentJob($creditLine))->handle(app(RandomApiService::class));
}

function expectCreditWarning(string $message, CreditLine $creditLine, ?Payment $payment): void
{
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($logged, $context) => $logged === $message
            && $context['credit_line_id'] === $creditLine->id
            && $context['user_id'] === $creditLine->user_id
            && $context['payment_id'] === $payment?->id)
        ->once();
}

beforeEach(function () {
    $this->user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9']);
});

test('it does nothing if credit line is not blocked', function () {
    $creditLine = CreditLine::factory()->create(['is_blocked' => false]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse();
});

test('it finds the FCV, stores it, refreshes the credit state, unblocks the line and completes the payment', function () {
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    fakeRandomCreditApi([360 => ['NVV', 'FCV']], ['CRSD' => 2000, 'CRSDVU' => 100]);

    runPendingCreditPaymentJob($creditLine);

    $creditLine->refresh();
    expect($creditLine->isBlocked())->toBeFalse()
        ->and($creditLine->blocking_payment_id)->toBeNull()
        ->and($creditLine->state['CRSDVU'])->toBe(100)
        ->and($payment->refresh()->status)->toBe('completed');

    $fcv = $payment->order->randomDocuments()->where('type', 'FCV')->first();
    expect($fcv)->not->toBeNull()
        ->and($fcv->idmaeedo)->toBe(361);
});

test('it accepts a BLV in the trace', function () {
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    fakeRandomCreditApi([360 => ['NVV', 'BLV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse()
        ->and($payment->refresh()->status)->toBe('completed')
        ->and($payment->order->randomDocuments()->where('type', 'BLV')->exists())->toBeTrue();
});

test('it does not attach the invoice twice when it was already attached', function () {
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    $fcv = RandomDocument::create(['idmaeedo' => 361, 'type' => 'FCV', 'document' => ['TIDO' => 'FCV']]);
    $payment->order->randomDocuments()->attach($fcv->idmaeedo);
    fakeRandomCreditApi([360 => ['NVV', 'FCV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($payment->order->randomDocuments()->where('type', 'FCV')->count())->toBe(1);
});

test('it keeps the line blocked and the payment processing if the trace has no invoice', function () {
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    fakeRandomCreditApi([360 => ['NVV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeTrue()
        ->and($creditLine->blocking_payment_id)->toBe($payment->id)
        ->and($payment->refresh()->status)->toBe('processing')
        ->and($payment->order->randomDocuments()->where('type', 'FCV')->exists())->toBeFalse();
});

test('it only evaluates the payment that blocked the line', function () {
    $stale = createCreditPaymentWithNvv($this->user, 100);
    $blocking = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $blocking);
    fakeRandomCreditApi([100 => ['NVV', 'FCV'], 360 => ['NVV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeTrue()
        ->and($blocking->refresh()->status)->toBe('processing')
        ->and($stale->refresh()->status)->toBe('processing');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/documentos/traza/100'));
});

test('it unblocks the line by the blocking payment even if an older payment is stuck in processing', function () {
    $stale = createCreditPaymentWithNvv($this->user, 100);
    $blocking = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $blocking);
    fakeRandomCreditApi([100 => ['NVV'], 360 => ['NVV', 'FCV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse()
        ->and($blocking->refresh()->status)->toBe('completed')
        ->and($stale->refresh()->status)->toBe('processing');
});

test('it falls back to the latest processing credit payment for lines blocked without a blocking payment', function () {
    $older = createCreditPaymentWithNvv($this->user, 100);
    $latest = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, null);
    fakeRandomCreditApi([100 => ['NVV'], 360 => ['NVV', 'FCV']]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse()
        ->and($latest->refresh()->status)->toBe('completed')
        ->and($older->refresh()->status)->toBe('processing');
});

test('it unblocks and warns when a line blocked without a blocking payment has no processing payments', function () {
    Log::spy();
    createCreditPaymentWithNvv($this->user, 360, 'completed');
    $creditLine = createBlockedCreditLine($this->user, null);
    fakeRandomCreditApi();

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse();
    expectCreditWarning('Credit line blocked without a processing credit payment, unblocking it', $creditLine, null);
    Http::assertNothingSent();
});

test('it unblocks and warns when the blocking payment is no longer processing', function (string $status) {
    Log::spy();
    $payment = createCreditPaymentWithNvv($this->user, 360, $status);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    fakeRandomCreditApi();

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse()
        ->and($creditLine->blocking_payment_id)->toBeNull()
        ->and($payment->refresh()->status)->toBe($status);
    expectCreditWarning('Credit payment that blocked the line is no longer processing, unblocking it', $creditLine, $payment);
    Http::assertNothingSent();
})->with(['completed', 'failed']);

test('it keeps the line blocked and warns when the order of the blocking payment has no NVV', function () {
    Log::spy();
    $payment = createCreditPaymentWithNvv($this->user);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    fakeRandomCreditApi();

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('processing');
    expectCreditWarning('Credit payment that blocked the line has no NVV, the line stays blocked', $creditLine, $payment);
});

test('it keeps the line blocked and warns when the trace query fails', function () {
    Log::spy();
    Sleep::fake();
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/documentos/traza/360" => Http::response(['message' => 'error'], 500),
    ]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('processing');
    expectCreditWarning('NVV trace query failed, the credit line stays blocked until the next cycle', $creditLine, $payment);
});

test('it still unblocks the line when the credit state can not be refreshed', function () {
    Sleep::fake();
    $payment = createCreditPaymentWithNvv($this->user, 360);
    $creditLine = createBlockedCreditLine($this->user, $payment);
    $state = $creditLine->state;
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([], 500),
        "{$baseUrl}/documentos/traza/360" => Http::response(['data' => [
            ['maeedo' => ['IDMAEEDO' => 361, 'TIDO' => 'FCV']],
        ]], 200),
    ]);

    runPendingCreditPaymentJob($creditLine);

    expect($creditLine->refresh()->isBlocked())->toBeFalse()
        ->and($creditLine->state)->toEqual($state)
        ->and($payment->refresh()->status)->toBe('completed');
});

test('it is unique per credit line', function () {
    $creditLine = CreditLine::factory()->create();

    expect(new ProcessPendingCreditPaymentJob($creditLine))
        ->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class)
        ->uniqueId()->toBe((string) $creditLine->id);
});

test('the migration backfills blocked lines with the latest processing credit payment of their user', function () {
    $migration = require database_path('migrations/2026_10_15_120000_add_blocking_payment_id_to_credit_lines_table.php');
    $migration->down();

    createCreditPaymentWithNvv($this->user, 100);
    $latest = createCreditPaymentWithNvv($this->user, 360);
    createCreditPaymentWithNvv($this->user, 400, 'completed');
    $blocked = CreditLine::factory()->create(['user_id' => $this->user->id, 'is_blocked' => true]);
    $unblocked = CreditLine::factory()->create(['user_id' => $this->user->id, 'is_blocked' => false]);
    $withoutPayments = CreditLine::factory()->create(['is_blocked' => true]);

    $migration->up();

    expect($blocked->refresh()->blocking_payment_id)->toBe($latest->id)
        ->and($unblocked->refresh()->blocking_payment_id)->toBeNull()
        ->and($withoutPayments->refresh()->blocking_payment_id)->toBeNull();
});
