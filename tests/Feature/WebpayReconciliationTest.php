<?php

use App\Enums\PaymentDocumentType;
use App\Jobs\ReconcileWebpayPendingPaymentsJob;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use App\Services\WebpayService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\OrderScenario;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\mock;
use function Pest\Laravel\postJson;

// The reconciliation job matches pending Webpay payments against the
// PaymentMethod row created by PaymentMethodSeeder ("transbank" / "Transbank").
function transbankPaymentMethod(): PaymentMethod
{
    return PaymentMethod::firstOrCreate(
        ['code' => 'transbank'],
        ['name' => 'Transbank', 'active' => true],
    );
}

it('pays an order via Webpay, then recovers it through status polling when the return redirect never happens', function () {
    $scenario = OrderScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $scenario->addProductToCart();

    $address = Address::factory()->create(['user_id' => $scenario->user->id]);

    $token = 'reconcile-token-' . uniqid();

    mock(WebpayService::class, function ($mock) use ($token) {
        // 1. Order payment: WebpayService creates the Transbank transaction
        // and, like the real implementation, records the pending Payment row.
        $mock->shouldReceive('createTransaction')
            ->once()
            ->andReturnUsing(function (Order $order, string $docType) use ($token) {
                app(PaymentService::class)->createPendingWebpayPayment(
                    $order,
                    transbankPaymentMethod(),
                    $token,
                    $docType,
                );

                return ['url' => 'https://webpay.test/init', 'token' => $token];
            });

        // 2. The user never comes back from Transbank's site. The
        // reconciliation job asks WebpayService for the transaction status
        // instead, and Transbank reports the payment was actually authorized.
        $mock->shouldReceive('getTransactionStatus')
            ->once()
            ->with($token)
            ->andReturn([
                'status' => 'AUTHORIZED',
                'amount' => 10000,
                'authorization_code' => '112233',
                'payment_type_code' => 'VD',
                'response_code' => 0,
                'installments_number' => 0,
                'installments_amount' => 0,
                'card_number' => '6623',
                'accounting_date' => '0719',
                'transaction_date' => now()->toIso8601String(),
            ]);
    });

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento" => Http::response(
            [
                'numero' => '0000000099',
                'tido' => 'NVV',
                'idmaeedo' => 1000,
                'estado' => ['codigo' => '1', 'mensaje' => 'Grabación exitosa'],
            ],
            200,
        ),
    ]);

    $payResponse = postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'transbank',
        'branch_id' => $scenario->branch->id,
        'payment_document_type' => PaymentDocumentType::RECEIPT,
    ]);

    $payResponse->assertOk();
    // Response wrapping in "data" depends on process-wide JsonResource state
    // (see ProfileResource::toArray(), which globally disables wrapping via
    // self::withoutWrapping()), so don't assume either shape here.
    $payload = $payResponse->json();
    $orderPayload = $payload['data']['order'] ?? $payload['order'];
    $orderId = $orderPayload['id'];
    assertDatabaseHas('orders', ['id' => $orderId]);

    $payment = Payment::where('token', $token)->first();
    expect($payment)->not->toBeNull();
    expect($payment->response_status)->toBe('pending');

    $order = Order::find($orderId);
    expect($order->status)->toBe('pending');

    // Simulate time passing beyond the configured grace period without the
    // return redirect ever reaching the backend.
    $graceMinutes = (int) config('webpay.reconciliation.grace_period_minutes');
    $payment->forceFill(['created_at' => now()->subMinutes($graceMinutes + 1)])->save();

    (new ReconcileWebpayPendingPaymentsJob())->handle(
        app(WebpayService::class),
        app(PaymentService::class),
    );

    $order->refresh();
    $payment->refresh();

    expect($order->status)->toBe('completed');
    expect($payment->response_status)->toBe('AUTHORIZED');
    expect($payment->auth_code)->toBe('112233');
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->last_status_checked_at)->not->toBeNull();

    expect(CartItem::where('user_id', $scenario->user->id)->count())->toBe(0);

    assertDatabaseHas('orders', ['id' => $orderId, 'random_document_number' => '0000000099']);
});

it('skips pending payments that are still within the grace period', function () {
    $paymentMethod = transbankPaymentMethod();
    $order = Order::factory()->create(['status' => 'pending']);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'fresh-token',
        'response_status' => 'pending',
        'created_at' => now(),
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock->shouldNotReceive('getTransactionStatus');
    app()->instance(WebpayService::class, $webpayServiceMock);

    (new ReconcileWebpayPendingPaymentsJob())->handle(
        app(WebpayService::class),
        app(PaymentService::class),
    );

    $payment->refresh();
    expect($payment->response_status)->toBe('pending');
    expect($payment->status_check_attempts)->toBe(0);
});

it('commits an authorized-but-unconfirmed transaction found still INITIALIZED on Transbank', function () {
    $paymentMethod = transbankPaymentMethod();
    $order = Order::factory()->create(['status' => 'pending', 'amount' => 10000]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'stale-token',
        'response_status' => 'pending',
        'created_at' => now()->subMinutes(30),
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock->shouldReceive('getTransactionStatus')
        ->once()
        ->with('stale-token')
        ->andReturn(['status' => 'INITIALIZED']);
    $webpayServiceMock->shouldReceive('getTransactionResult')
        ->once()
        ->with('stale-token')
        ->andReturn([
            'status' => 'AUTHORIZED',
            'authorization_code' => '998877',
            'amount' => 10000,
        ]);
    app()->instance(WebpayService::class, $webpayServiceMock);

    (new ReconcileWebpayPendingPaymentsJob())->handle(
        app(WebpayService::class),
        app(PaymentService::class),
    );

    $order->refresh();
    $payment->refresh();

    expect($order->status)->toBe('completed');
    expect($payment->response_status)->toBe('AUTHORIZED');
    expect($payment->auth_code)->toBe('998877');
});

it('marks the order as failed after exhausting all status check attempts', function () {
    $paymentMethod = transbankPaymentMethod();
    $maxAttempts = (int) config('webpay.reconciliation.max_status_check_attempts');

    $order = Order::factory()->create(['status' => 'pending']);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'unreachable-token',
        'response_status' => 'pending',
        'created_at' => now()->subHours(2),
        'status_check_attempts' => $maxAttempts - 1,
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock->shouldReceive('getTransactionStatus')
        ->once()
        ->with('unreachable-token')
        ->andThrow(new \Exception('Transbank unavailable'));
    app()->instance(WebpayService::class, $webpayServiceMock);

    (new ReconcileWebpayPendingPaymentsJob())->handle(
        app(WebpayService::class),
        app(PaymentService::class),
    );

    $order->refresh();
    $payment->refresh();

    expect($payment->status_check_attempts)->toBe($maxAttempts);
    expect($payment->response_status)->toBe('FAILED');
    expect($order->status)->toBe('failed');
});
