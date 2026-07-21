<?php

use App\Enums\BranchType;
use App\Enums\PaymentDocumentType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Services\WebpayService;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\OrderScenario;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\mock;
use function Pest\Laravel\postJson;

describe('OrderController', function () {

    describe('index', function () {
        test('can list authenticated user orders', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);

            Order::factory()->count(3)->create([
                'user_id' => $scenario->user->id
            ]);

            $otherUser = createUserWithPermissions(['read-own-orders', 'create-orders']);
            Order::factory()->count(2)->create([
                'user_id' => $otherUser->id
            ]);

            $response = getJson(route('orders.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure($scenario->listJsonStructure);
        });

        test('requires authentication to list orders', function () {
            $response = getJson(route('orders.index'));

            $response->assertUnauthorized();
        });
    });

    describe('payOrder', function () {
        test('can initiate payment for an order from cart', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->withArgs(function (Order $order, string $docType) {
                        return $docType === PaymentDocumentType::RECEIPT;
                    })
                    ->andReturn([
                        'url' => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk()
                ->assertJsonStructure($scenario->payJsonStructure);

            assertDatabaseHas('orders', [
                'user_id' => $scenario->user->id,
                'status'  => 'pending'
            ]);

            assertDatabaseHas('order_items', [
                'product_id' => Product::first()->id,
                'quantity'   => 2,
                'unit'       => 'kg'
            ]);
        });

        test('cannot pay if cart is empty', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertBadRequest()
                ->assertJson(['message' => 'El carrito está vacío']);
        });

        test('cannot pay with an address that does not belong to user', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $otherUser = createUserWithPermissions(['read-own-orders', 'create-orders']);
            $address = Address::factory()->create([
                'user_id' => $otherUser->id
            ]);

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires a valid address to pay', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $response = postJson(route('orders.pay'), [
                'address_id'             => 999999,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires address_id field', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $response = postJson(route('orders.pay'), []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires authentication to pay', function () {
            $address = Address::factory()->create();

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertUnauthorized();
        });

        test('handles payment service errors', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andThrow(new \Exception('Error de conexión con Webpay'));
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(500)
                ->assertJsonStructure($scenario->payErrorJsonStructure);
        });

        test('correctly calculates subtotal and amount', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart(150, 3);

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(450.0);
            expect($order->shipping_cost)->toBe(5990.0);
            expect($order->amount)->toBe(6440.0);
        });

        test('rounds cart subtotal to whole pesos before sending payment amount', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart(1152.5, 3);
            $scenario->addProductToCart(100.4, 1);

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->withArgs(function (Order $order, string $docType) {
                        return $docType === PaymentDocumentType::RECEIPT
                            && $order->subtotal === 3558.0
                            && $order->shipping_cost === 5990.0
                            && $order->amount === 9548.0;
                    })
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();
        });

        test('includes user and address metadata in order', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->order_meta)->toHaveKey('user');
            expect($order->order_meta)->toHaveKey('address');
            expect($order->order_meta['user']['id'])->toBe($scenario->user->id);
            expect($order->order_meta['address']['id'])->toBe($address->id);
        });

        test('stores branch_id and notes on order', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
                'notes'                  => 'Leave at the door',
            ]);

            $response->assertOk();

            assertDatabaseHas('orders', [
                'id'        => Order::first()->id,
                'branch_id' => $scenario->branch->id,
                'notes'     => 'Leave at the door',
            ]);
        });

        test('payment receives generate_random_doc_type via webpay service', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create([
                'user_id' => $scenario->user->id
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->withArgs(function (Order $order, string $docType) {
                        return $docType === PaymentDocumentType::INVOICE;
                    })
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::INVOICE,
            ]);

            $response->assertOk();
        });

        test('defaults to principal branch when branch_id is omitted', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $principalBranch = Branch::factory()->create([
                'user_id'     => $scenario->user->id,
                'branch_type' => BranchType::PRIMARY,
            ]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            assertDatabaseHas('orders', [
                'id'        => Order::first()->id,
                'branch_id' => $principalBranch->id,
            ]);
        });

        test('notes defaults to empty string when not provided', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            assertDatabaseHas('orders', [
                'id'    => Order::first()->id,
                'notes' => '',
            ]);
        });
    });

    describe('payOrder validation', function () {
        it('validates branch_id exists', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => 99999,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('branch_id');
        });

        it('requires payment_document_type field', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $response = postJson(route('orders.pay'), [
                'address_id'    => $address->id,
                'payment_method' => 'transbank',
                'branch_id'     => $scenario->branch->id,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_document_type');
        });

        it('validates payment_document_type is a valid value', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $response = postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $scenario->branch->id,
                'payment_document_type'  => 'invalid_type',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_document_type');
        });
    });
});
