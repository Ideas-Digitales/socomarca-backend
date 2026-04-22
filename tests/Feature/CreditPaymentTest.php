<?php

use App\Models\User;
use App\Models\Address;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// beforeEach(function () {
//     $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
//     $this->seed(\Database\Seeders\PaymentMethodSeeder::class);
// });

test('it can process a credit line payment successfully', function () {
    // $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    /** @var TestCase $this */

    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    if (!$user->hasRole('customer')) {
        $user->assignRole('customer');
    }

    $user->assignRole('customer');

    $address = Address::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create();
    $price = \App\Models\Price::factory()->create(['product_id' => $product->id, 'unit' => 'UN']);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'UN'
    ]);

    $paymentMethod = PaymentMethod::where('code', 'random_credit')->firstOrFail();

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 50092358399999.99,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ], 200),
        "{$baseUrl}/web32/documento" => Http::response([
            "numero" => "0000000001",
            "tido" => "FCV",
            "idmaeedo" => 657,
            "uidxmaeedo" => "2CEE468D-35B7-4CBA-A243-E2D20C13C8D7",
            "vabrdo" => 7140,
            "moneda" => "CLP",
            "estado" => [
                "codigo" => "1",
                "mensaje" => "Grabación exitosa"
            ]
        ], 200),
    ]);

    $response = $this->actingAs($user)->postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'random_credit'
    ]);

    $order = Order::where('user_id', $user->id)->first();

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'transaction' => ['status'],
                'payment' => [
                    'auth_code',
                    'amount',
                    'response_status',
                    'token',
                    'paid_at',
                    'payment_method',
                    'order'
                ]
            ]
        ])
        ->assertJsonPath('data.transaction.status', 'AUTHORIZED')
        ->assertJsonPath('data.payment.response_status', 'AUTHORIZED');

    expect($response->json('data.payment.amount'))->toEqual($order->amount);

    expect($order->status)->toBe('completed');

    $payment = $order->payments()->first();
    expect($payment->response_status)->toBe('AUTHORIZED');
    expect($payment->payment_method_id)->toBe($paymentMethod->id);

    // Cart is empty
    expect(CartItem::where('user_id', $user->id)->count())->toBe(0);

    // Find order by payment method applying filters
    $response = $this->actingAs($user)->getJson(route('orders.index', [
        'payment_method_code' => 'random_credit'
    ]));

    expect($response->json('data.0.payments.0.auth_code'))->toBe($payment->auth_code);
    expect($response->json('data.0.payments.0.amount'))->toBe($payment->amount);
    expect($response->json('data.0.payments.0.response_status'))->toBe($payment->response_status);
    expect($response->json('data.0.payments.0.payment_method.code'))->toBe("random_credit");

    // Get order without applying filters
    $response = $this->actingAs($user)->getJson(route('orders.index'));

    expect($response->json('data.0.payments.0.auth_code'))->toBe($payment->auth_code);
    expect($response->json('data.0.payments.0.amount'))->toBe($payment->amount);
    expect($response->json('data.0.payments.0.response_status'))->toBe($payment->response_status);
    expect($response->json('data.0.payments.0.payment_method.code'))->toBe("random_credit");

    // Getting empty orders array when providing another payment method code
    // as filter parameter
    $response = $this->actingAs($user)->getJson(route('orders.index', [
        'payment_method_code' => 'transbank'
    ]));

    expect($response->json('data'))->toBeEmpty();
});

test('it handles credit line payment failure correctly', function () {
    /** @var TestCase $this */

    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    if (!$user->hasRole('customer')) {
        $user->assignRole('customer');
    }

    $user->assignRole('customer');

    $address = Address::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create();
    $price = \App\Models\Price::factory()->create(['product_id' => $product->id, 'unit' => 'UN']);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'UN'
    ]);

    $paymentMethod = PaymentMethod::where('code', 'random_credit')->firstOrFail();

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 50092358399999.99,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ], 200),
        "{$baseUrl}/web32/documento" => Http::response([
            'message' => '5B8F5331-D5AF-4C80-A1E8-E7C9E096150A| El funcionario del documento no es válido',
            'errorId' => '3YiKmHn_'
        ], 200),
    ]);

    $response = $this->actingAs($user)->postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'random_credit'
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.transaction.status', 'FAILED');

    $order = Order::where('user_id', $user->id)->first();
    expect($order->status)->toBe('failed');

    $payment = $order->payments()->first();
    expect($payment->response_status)->toBe('FAILED');
    expect($payment->payment_method_id)->toBe($paymentMethod->id);
});

test('it responds with 500 when Random api returns invalid credit info', function () {
    /** @var TestCase $this */

    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    if (!$user->hasRole('customer')) {
        $user->assignRole('customer');
    }

    $address = Address::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create();
    $price = \App\Models\Price::factory()->create(['product_id' => $product->id, 'unit' => 'UN']);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'UN'
    ]);

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response([
            'KOEN' => '12345678-9',
            // Missing SUEN, CRSD, etc., making it an invalid response
        ], 200)
    ]);

    $response = $this->actingAs($user)->postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'random_credit'
    ]);

    $response->assertStatus(500)
        ->assertJsonFragment([
            'message' => 'No se pudo obtener el crédito del cliente'
        ]);
});

test('it fails when the random_credit payment method does not exist in the database', function () {
    /** @var TestCase $this */

    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    if (!$user->hasRole('customer')) {
        $user->assignRole('customer');
    }

    $address = Address::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create();
    \App\Models\Price::factory()->create(['product_id' => $product->id, 'unit' => 'UN']);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'UN'
    ]);

    // Force deletion of the payment method to simulate the missing case
    \App\Models\PaymentMethod::where('code', 'random_credit')->delete();

    $response = $this->actingAs($user)->postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'random_credit'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payment_method']);
});

test('it returns insufficient credit message if order amount exceeds available random credit', function () {
    /** @var TestCase $this */

    $user = User::factory()->create(['rut' => '9876543-2', 'branch_code' => 'VALPO']);
    if (!$user->hasRole('customer')) {
        $user->assignRole('customer');
    }

    $address = Address::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create();
    // Exorbitant price to exceed the credit limit
    $price = \App\Models\Price::factory()->create(['product_id' => $product->id, 'unit' => 'UN', 'price' => 500000]);

    CartItem::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'UN'
    ]);

    $baseUrl = config('random.url');
    // Low credit setup
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake_token'], 200),
        "{$baseUrl}/gestion/credito/resumen/{$user->rut}/{$user->branch_code}" => Http::response([
            'KOEN' => $user->rut,
            'SUEN' => $user->branch_code,
            'CRSD' => 2000,     // Total Credit
            'CRSDVU' => 1500,   // Used Credit
            'CRSDVV' => 0,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ], 200)
    ]);

    $response = $this->actingAs($user)->postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'random_credit'
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => false,
            'message' => 'Crédito insuficiente',
            'data' => [
                'transaction' => ['status' => 'FAILED']
            ]
        ]);
});
