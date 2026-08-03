<?php

use App\Enums\BranchType;
use App\Listeners\CreateWebpayRandomDocument;
use App\Models\Branch;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Scopes\SecondaryBranchesScope;
use App\Models\User;
use App\Services\WebpayService;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Scenarios\WebpayReturnScenario;

use function Pest\Laravel\getJson;
use function Pest\Laravel\instance;

it('handles a successful payment, updates the order status and creates the random document', function () {
    Storage::fake('s3');

    $scenario = WebpayReturnScenario::make();

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock
        ->shouldReceive('getTransactionResult')
        ->with('fake_token_ws')
        ->once()
        ->andReturn([
            'status' => 'AUTHORIZED',
            'authorization_code' => '7654321',
            'amount' => 10000,
            'buy_order' => $scenario->order->id,
        ]);
    instance(WebpayService::class, $webpayServiceMock);

    // Fake Random API response for NVV creation
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento*" => Http::response(
            [
                'numero' => '0000000088',
                'tido' => 'NVV',
                'idmaeedo' => 999,
                'estado' => [
                    'codigo' => '1',
                    'mensaje' => 'Grabación exitosa',
                ],
            ],
            200,
        ),
    ]);

    $response = getJson(route('webpay.return', ['token_ws' => 'fake_token_ws']));

    $response->assertStatus(200)->assertJson([
        'success' => true,
        'message' => 'Pago exitoso',
        'data' => [
            'status' => 'AUTHORIZED',
        ],
    ]);

    $scenario->order->refresh();
    expect($scenario->order->status)->toBe('completed');
    expect($scenario->order->random_document_number)->toBe('0000000088');

    $scenario->payment->refresh();
    expect($scenario->payment->response_status)->toBe('AUTHORIZED');
    expect($scenario->payment->auth_code)->toBe('7654321');
    expect($scenario->payment->paid_at)->not->toBeNull();

    expect(CartItem::where('user_id', $scenario->user->id)->count())->toBe(0);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl, $scenario) {
        if (!str_starts_with($request->url(), "{$baseUrl}/web32/documento")) {
            return false;
        }

        $payload = $request->data();

        return isset($payload['datos']) &&
            $payload['datos']['codigoEntidad'] === $scenario->user->user_code &&
            $payload['datos']['tido'] === 'NVV' &&
            count($payload['datos']['lineas']) === 1 &&
            $payload['datos']['lineas'][0]['codigoProducto'] === $scenario->product->sku &&
            $payload['datos']['lineas'][0]['cantidad'] === 2;
    });

    expect($scenario->order->randomDocuments()->count())->toBe(1);
    expect($scenario->order->randomDocuments()->first()->idmaeedo)->toBe(999);
    expect($scenario->order->random_document_number)->toBe('0000000088');
});

it('handles a successful payment when choosing the primary branch', function () {
    Storage::fake('s3');

    $scenario = WebpayReturnScenario::make(['branch_type' => BranchType::PRIMARY]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock
        ->shouldReceive('getTransactionResult')
        ->with('fake_token_ws')
        ->once()
        ->andReturn([
            'status' => 'AUTHORIZED',
            'authorization_code' => '7654321',
            'amount' => 10000,
            'buy_order' => $scenario->order->id,
        ]);
    instance(WebpayService::class, $webpayServiceMock);

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        "{$baseUrl}/web32/documento*" => Http::response(
            [
                'numero' => '0000000088',
                'tido' => 'NVV',
                'idmaeedo' => 999,
                'estado' => [
                    'codigo' => '1',
                    'mensaje' => 'Grabación exitosa',
                ],
            ],
            200,
        ),
    ]);

    $response = getJson(route('webpay.return', ['token_ws' => 'fake_token_ws']));

    $response->assertStatus(200)->assertJson([
        'success' => true,
        'message' => 'Pago exitoso',
        'data' => [
            'status' => 'AUTHORIZED',
        ],
    ]);

    $scenario->order->refresh();
    expect($scenario->order->status)->toBe('completed');
    expect($scenario->order->random_document_number)->toBe('0000000088');

    $scenario->payment->refresh();
    expect($scenario->payment->response_status)->toBe('AUTHORIZED');
    expect($scenario->payment->auth_code)->toBe('7654321');
    expect($scenario->payment->paid_at)->not->toBeNull();

    expect(CartItem::where('user_id', $scenario->user->id)->count())->toBe(0);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl, $scenario) {
        if (!str_starts_with($request->url(), "{$baseUrl}/web32/documento")) {
            return false;
        }

        $payload = $request->data();

        return isset($payload['datos']) &&
            $payload['datos']['codigoEntidad'] === $scenario->user->user_code &&
            $payload['datos']['tido'] === 'NVV' &&
            count($payload['datos']['lineas']) === 1 &&
            $payload['datos']['lineas'][0]['codigoProducto'] === $scenario->product->sku &&
            $payload['datos']['lineas'][0]['cantidad'] === 2;
    });

    expect($scenario->order->randomDocuments()->count())->toBe(1);
    expect($scenario->order->randomDocuments()->first()->idmaeedo)->toBe(999);
    expect($scenario->order->random_document_number)->toBe('0000000088');
    expect(
        $scenario->order
            ->branch()
            ->withoutGlobalScope(SecondaryBranchesScope::class)
            ->first()->id,
    )->toBe($scenario->branch->id);
});

it('handles a failed transaction', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $paymentMethod = PaymentMethod::factory()->create(['code' => 'webpay']);

    Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'failed_token_ws',
        'status' => 'pending',
        'generate_random_doc_type' => \App\Enums\PaymentDocumentType::RECEIPT,
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock
        ->shouldReceive('getTransactionResult')
        ->with('failed_token_ws')
        ->once()
        ->andReturn(['status' => 'FAILED']);
    instance(WebpayService::class, $webpayServiceMock);

    Http::fake();

    $response = getJson(route('webpay.return', ['token_ws' => 'failed_token_ws']));

    $response->assertStatus(200)->assertJson([
        'success' => false,
        'message' => 'Pago fallido',
        'data' => [
            'status' => 'FAILED',
        ],
    ]);

    $order->refresh();
    expect($order->status)->toBe('failed');

    // Document creation URL should not have been called
    Http::assertNothingSent();
});

it('handles a user-aborted transaction', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $paymentMethod = PaymentMethod::factory()->create(['code' => 'webpay']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'aborted_token_ws',
        'status' => 'pending',
    ]);

    $response = getJson(route('webpay.return', ['TBK_TOKEN' => 'aborted_token_ws']));

    $response->assertStatus(400)->assertJson([
        'success' => false,
        'message' => 'Pago abortado por el usuario',
        'token' => 'aborted_token_ws',
    ]);

    $payment->refresh();
    expect($payment->response_status)->toBe('failed');
});

it('handles a timeout', function () {
    $response = getJson(route('webpay.return'));

    $response->assertStatus(408)->assertJson([
        'success' => false,
        'message' => 'Tiempo de espera agotado',
    ]);
});

it('queues the random document listener on an authorized payment', function () {
    Queue::fake();

    $user = User::factory()->create([
        'rut' => '12345678-9',
        'user_code' => '12345678-9',
    ]);
    $branch = Branch::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $paymentMethod = PaymentMethod::factory()->create(['code' => 'webpay']);
    Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'queue_token_ws',
        'status' => 'pending',
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock
        ->shouldReceive('getTransactionResult')
        ->with('queue_token_ws')
        ->once()
        ->andReturn([
            'status' => 'AUTHORIZED',
            'authorization_code' => '1',
        ]);
    instance(WebpayService::class, $webpayServiceMock);

    getJson(route('webpay.return', ['token_ws' => 'queue_token_ws']));

    Queue::assertPushed(CallQueuedListener::class, function ($job) {
        return $job->class === CreateWebpayRandomDocument::class;
    });
});
