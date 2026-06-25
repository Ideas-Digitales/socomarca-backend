<?php

namespace Tests\Feature;

use App\Enums\PaymentDocumentType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\CartItem;
use App\Services\WebpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(RefreshDatabase::class);

test('webpay return handles successful payment, updates order status and creates random document', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9']);
    $user->assignRole('customer');

    $branch = Branch::factory()->create(['user_id' => $user->id]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'amount' => 10000,
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $product = Product::factory()->create(['sku' => 'TEST-SKU-123']);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2
    ]);

    CartItem::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $paymentMethod = \App\Models\PaymentMethod::factory()->create(['code' => 'webpay']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'fake_token_ws',
        'status' => 'pending',
        'response_status' => 'INITIALIZED',
        'generate_random_doc_type' => PaymentDocumentType::INVOICE,
    ]);

    // Mock WebpayService to return successful authorization
    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock->shouldReceive('getTransactionResult')
        ->with('fake_token_ws')
        ->once()
        ->andReturn([
            'status' => 'AUTHORIZED',
            'authorization_code' => '7654321',
            'amount' => 10000,
            'buy_order' => $order->id,
        ]);

    $this->instance(WebpayService::class, $webpayServiceMock);

    // Fake Random API response for NVV creation
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/web32/documento" => Http::response([
            "numero" => "0000000088",
            "tido" => "NVV",
            "idmaeedo" => 999,
            "estado" => [
                "codigo" => "1",
                "mensaje" => "Grabación exitosa"
            ]
        ], 200),
    ]);

    // Act
    $response = $this->actingAs($user)->getJson(route('webpay.return', ['token_ws' => 'fake_token_ws']));

    // Assert Response
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Pago exitoso',
            'data' => [
                'status' => 'AUTHORIZED'
            ]
        ]);

    // Assert Order changed to completed
    $order->refresh();
    expect($order->status)->toBe('completed');

    // Assert Payment updated
    $payment->refresh();
    expect($payment->response_status)->toBe('AUTHORIZED');
    expect($payment->auth_code)->toBe('7654321');
    expect($payment->paid_at)->not->toBeNull();

    // Assert Cart is cleared
    expect(CartItem::where('user_id', $user->id)->count())->toBe(0);

    // Verify Random NVV Document payload sent
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl, $user, $product) {
        if (!str_starts_with($request->url(), "{$baseUrl}/web32/documento")) {
            return false;
        }

        $payload = $request->data();

        return isset($payload['datos'])
            && $payload['datos']['codigoEntidad'] === $user->user_code
            && $payload['datos']['tido'] === 'NVV'
            && count($payload['datos']['lineas']) === 1
            && $payload['datos']['lineas'][0]['codigoProducto'] === $product->sku
            && $payload['datos']['lineas'][0]['cantidad'] === 2;
    });

    // Assert Document attached to the Order
    expect($order->randomDocuments()->count())->toBe(1);
    expect($order->randomDocuments()->first()->idmaeedo)->toBe(999);
    expect($order->internal_sale_note)->toBe(999);
});

test('webpay return handles failed transaction', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $paymentMethod = \App\Models\PaymentMethod::factory()->create(['code' => 'webpay']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'failed_token_ws',
        'status' => 'pending',
        'generate_random_doc_type' => PaymentDocumentType::RECEIPT,
    ]);

    $webpayServiceMock = Mockery::mock(WebpayService::class);
    $webpayServiceMock->shouldReceive('getTransactionResult')
        ->with('failed_token_ws')
        ->once()
        ->andReturn([
            'status' => 'FAILED' // transbank failed status
        ]);

    $this->instance(WebpayService::class, $webpayServiceMock);

    // Act
    $response = $this->actingAs($user)->getJson(route('webpay.return', ['token_ws' => 'failed_token_ws']));

    $response->assertStatus(200)
        ->assertJson([
            'success' => false,
            'message' => 'Pago fallido',
            'data' => [
                'status' => 'FAILED'
            ]
        ]);

    $order->refresh();
    expect($order->status)->toBe('failed');

    // Document creation URL should not have been called
    Http::fake();
    Http::assertNothingSent();
});

test('webpay return handles user aborted transaction', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'branch_id' => $branch->id,
        'notes' => '',
    ]);

    $paymentMethod = \App\Models\PaymentMethod::factory()->create(['code' => 'webpay']);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'token' => 'aborted_token_ws',
        'status' => 'pending',
    ]);

    // Act
    $response = $this->actingAs($user)->getJson(route('webpay.return', ['TBK_TOKEN' => 'aborted_token_ws']));

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'message' => 'Pago abortado por el usuario',
            'token' => 'aborted_token_ws'
        ]);

    $payment->refresh();
    expect($payment->response_status)->toBe('failed');
});

test('webpay return handles timeout', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->getJson(route('webpay.return'));

    $response->assertStatus(408)
        ->assertJson([
            'success' => false,
            'message' => 'Tiempo de espera agotado'
        ]);
});
