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
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('it does nothing if credit line is not blocked', function () {
    $creditLine = CreditLine::factory()->create(['is_blocked' => false]);

    $job = new ProcessPendingCreditPaymentJob($creditLine);
    $job->handle(app(RandomApiService::class));

    expect($creditLine->refresh()->isBlocked())->toBeFalse();
});

test('it logs an error if there are more than 2 processing payments', function () {
    $user = User::factory()->create();
    $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);
    $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'random_credit'], ['name' => 'Random', 'active' => true]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    // Create 3 processing payments for the same order/user
    Payment::factory()->count(3)->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'status' => 'processing',
    ]);

    Log::shouldReceive('error')
        ->once()
        ->with('Usuario con múltiples pagos a crédito en proceso', \Mockery::type('array'));

    // Job needs a mock to prevent real external calls if it proceeds
    $baseUrl = config('random.url');
    Illuminate\Support\Facades\Http::fake([
        "{$baseUrl}/login" => Illuminate\Support\Facades\Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/documentos/traza/*" => Illuminate\Support\Facades\Http::response(['data' => []], 200),
    ]);

    $job = new ProcessPendingCreditPaymentJob($creditLine);
    $job->handle(app(RandomApiService::class));
});

test('it processes trace, finds FCV, unblocks credit and completes payment', function () {
    $user = User::factory()->create(['rut' => '12345678-9']);
    $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true, 'branch_code' => 'CM']);
    $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'random_credit'], ['name' => 'Random', 'active' => true]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'status' => 'processing',
    ]);

    // Create the NVV Document and link to order
    $nvvDoc = RandomDocument::create([
        'idmaeedo' => 360,
        'type' => 'NVV',
        'document' => ['TIDO' => 'NVV', 'NUDO' => '0000140684']
    ]);
    $order->randomDocuments()->attach($nvvDoc->idmaeedo);

    $expectedCreditState = [
        'KOEN' => '12345678-9',
        'SUEN' => 'CM',
        'CRSD' => 2000,
        'CRSDVU' => 100,
        'CRSDVV' => 50,
        'CRSDCU' => 0,
        'CRSDCV' => 0
    ];

    // Mock API
    $baseUrl = config('random.url');
    Illuminate\Support\Facades\Http::fake([
        "{$baseUrl}/login" => Illuminate\Support\Facades\Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/*" => Illuminate\Support\Facades\Http::response($expectedCreditState, 200),
        "{$baseUrl}/documentos/traza/360" => Illuminate\Support\Facades\Http::response([
            'data' => [
                [
                    'maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV'] // La NVV original en la traza
                ],
                [
                    'maeedo' => ['IDMAEEDO' => 361, 'TIDO' => 'FCV', 'NUDO' => '0000175730'] // La Factura
                ]
            ]
        ], 200),
    ]);

    $job = new ProcessPendingCreditPaymentJob($creditLine);
    $job->handle(app(RandomApiService::class));

    $creditLine->refresh();
    expect($creditLine->isBlocked())->toBeFalse();
    expect($payment->refresh()->status)->toBe('completed');
    expect($creditLine->state)->toEqual($expectedCreditState);

    // El documento FCV fue guardado asociado a la orden?
    $fcvDoc = $order->randomDocuments()->where('type', 'FCV')->first();
    expect($fcvDoc)->not->toBeNull();
    expect($fcvDoc->idmaeedo)->toBe(361);
});

test('it keeps processing and blocked if FCV is not in trace', function () {
    $user = User::factory()->create();
    $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);
    $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'random_credit'], ['name' => 'Random', 'active' => true]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'status' => 'processing',
    ]);

    $nvvDoc = RandomDocument::create([
        'idmaeedo' => 360,
        'type' => 'NVV',
        'document' => ['TIDO' => 'NVV']
    ]);
    $order->randomDocuments()->attach($nvvDoc->idmaeedo);

    $baseUrl = config('random.url');
    Illuminate\Support\Facades\Http::fake([
        "{$baseUrl}/login" => Illuminate\Support\Facades\Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/documentos/traza/360" => Illuminate\Support\Facades\Http::response([
            'data' => [
                [
                    'maeedo' => ['IDMAEEDO' => 360, 'TIDO' => 'NVV'] // Solo está la NVV
                ]
            ]
        ], 200),
    ]);

    $job = new ProcessPendingCreditPaymentJob($creditLine);
    $job->handle(app(RandomApiService::class));

    // Nada cambió
    expect($creditLine->refresh()->isBlocked())->toBeTrue();
    expect($payment->refresh()->status)->toBe('processing');
    expect($order->randomDocuments()->where('type', 'FCV')->exists())->toBeFalse();
});
