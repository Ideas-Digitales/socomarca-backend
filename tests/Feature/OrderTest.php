<?php

use App\Enums\BranchType;
use App\Enums\PaymentDocumentType;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\Siteinfo;
use App\Models\User;
use App\Services\VatService;
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

        it('lists the orders placed for the user by its primary branch', function () {
            $scenario = OrderScenario::make();
            $branch = $scenario->makeSecondaryBranch();
            Sanctum::actingAs($branch, ['api-access']);

            $ownOrder = Order::factory()->create(['user_id' => $branch->id]);
            $orderForBranch = Order::factory()->create([
                'user_id' => $scenario->user->id,
                'customer_id' => $branch->id,
            ]);
            Order::factory()->create(['user_id' => $scenario->user->id]);

            $response = getJson(route('orders.index'))
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonStructure($scenario->listJsonStructure);

            expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
                ->toBe([$ownOrder->id, $orderForBranch->id]);
            expect(collect($response->json('data'))->firstWhere('id', $orderForBranch->id)['customer']['id'])
                ->toBe($branch->id);
        });

        it('lists the orders the user placed for its secondary branches', function () {
            $scenario = OrderScenario::make();
            $branch = $scenario->makeSecondaryBranch();
            Sanctum::actingAs($scenario->user, ['api-access']);

            Order::factory()->create([
                'user_id' => $scenario->user->id,
                'customer_id' => $branch->id,
            ]);

            getJson(route('orders.index', ['payment_method_code' => 'transbank']))
                ->assertOk()
                ->assertJsonCount(0, 'data');

            getJson(route('orders.index'))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.customer.id', $branch->id);
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
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(450.0);
            expect($order->total)->toBe(536.0); // 450 plus 19% VAT
            expect($order->shipping_cost)->toBe(5990.0);
            expect($order->amount)->toBe(6526.0);
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
                            && $order->total === 4234.0
                            && $order->shipping_cost === 5990.0
                            && $order->amount === 10224.0;
                    })
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
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->order_meta)->toHaveKey('user');
            expect($order->order_meta)->toHaveKey('address');
            expect($order->order_meta['user']['id'])->toBe($scenario->user->id);
            expect($order->order_meta['address']['id'])->toBe($address->id);
        });

        test('stores notes on order', function () {
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
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
                'notes'                  => 'Leave at the door',
            ]);

            $response->assertOk();

            assertDatabaseHas('orders', [
                'id'        => Order::first()->id,
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
                'payment_document_type'  => PaymentDocumentType::INVOICE,
            ]);

            $response->assertOk();
        });

        it('places the order for the authenticated user when customer_id is omitted', function () {
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

            postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ])
                ->assertOk()
                ->assertJsonPath('data.order.customer.id', $scenario->user->id);

            assertDatabaseHas('orders', [
                'id'          => Order::first()->id,
                'user_id'     => $scenario->user->id,
                'customer_id' => $scenario->user->id,
            ]);
        });

        it('places the order for a secondary branch of the same entity', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();
            $branch = $scenario->makeSecondaryBranch();
            $address = Address::factory()->create(['user_id' => $branch->id]);

            mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            postJson(route('orders.pay'), [
                'customer_id'            => $branch->id,
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ])
                ->assertOk()
                ->assertJsonPath('data.order.customer', [
                    'id'          => $branch->id,
                    'name'        => $branch->name,
                    'user_code'   => '77528378',
                    'branch_code' => 'LO',
                    'branch_type' => BranchType::SECONDARY,
                ]);

            $order = Order::first();
            expect($order->user_id)->toBe($scenario->user->id)
                ->and($order->customer_id)->toBe($branch->id)
                ->and($order->order_meta['address']['id'])->toBe($address->id);
        });
    });

    describe('payOrder customer', function () {
        $pay = function (OrderScenario $scenario, int $customerId, ?int $addressId = null) {
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            return postJson(route('orders.pay'), [
                'customer_id'            => $customerId,
                'address_id'             => $addressId ?? Address::factory()->create(['user_id' => $customerId])->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);
        };

        it('rejects a customer that does not exist', function () use ($pay) {
            $scenario = OrderScenario::make();
            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $pay($scenario, 99999, $address->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('customer_id');
        });

        it('rejects a secondary branch of another entity', function () use ($pay) {
            $scenario = OrderScenario::make();
            $branch = $scenario->makeSecondaryBranch(['user_code' => '11111111']);

            $pay($scenario, $branch->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('customer_id');
        });

        it('rejects an inactive secondary branch', function () use ($pay) {
            $scenario = OrderScenario::make();
            $branch = $scenario->makeSecondaryBranch(['is_active' => false]);

            $pay($scenario, $branch->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('customer_id');
        });

        it('does not let a secondary branch order for another branch', function () use ($pay) {
            $scenario = OrderScenario::make();
            $scenario->user->update(['branch_type' => BranchType::SECONDARY]);
            $branch = $scenario->makeSecondaryBranch();

            $pay($scenario, $branch->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('customer_id');
        });

        it('requires the address to belong to the customer', function () use ($pay) {
            $scenario = OrderScenario::make();
            $branch = $scenario->makeSecondaryBranch();
            $buyerAddress = Address::factory()->create(['user_id' => $scenario->user->id]);

            $pay($scenario, $branch->id, $buyerAddress->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('address_id')
                ->assertJsonMissingValidationErrors('customer_id');
        });
    });

    describe('payOrder defaults', function () {

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
        it('requires payment_document_type field', function () {
            $scenario = OrderScenario::make();
            Sanctum::actingAs($scenario->user, ['api-access']);
            $scenario->addProductToCart();

            $address = Address::factory()->create(['user_id' => $scenario->user->id]);

            $response = postJson(route('orders.pay'), [
                'address_id'    => $address->id,
                'payment_method' => 'transbank',
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
                'payment_document_type'  => 'invalid_type',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_document_type');
        });
    });
});

describe('Order prices', function () {
    $mockWebpay = function (): void {
        mock(WebpayService::class, function ($mock) {
            $mock->shouldReceive('createTransaction')
                ->andReturn([
                    'url' => 'https://webpay.test/init',
                    'token' => 'test-token-123',
                ]);
        });
    };

    $pay = function (User $customer) {
        return postJson(route('orders.pay'), [
            'customer_id' => $customer->id,
            'address_id' => Address::factory()->create(['user_id' => $customer->id])->id,
            'payment_method' => 'transbank',
            'payment_document_type' => PaymentDocumentType::RECEIPT,
        ]);
    };

    it('stores and exposes the price list of every order item', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);
        $scenario->addProductToCart(1000, 2);
        $mockWebpay();

        $pay($scenario->user)
            ->assertOk()
            ->assertJsonPath('data.order.order_items.0.price_list_id', OrderScenario::PRICE_LIST);

        assertDatabaseHas('order_items', [
            'order_id' => Order::first()->id,
            'price' => 1000,
            'price_list_id' => OrderScenario::PRICE_LIST,
        ]);

        getJson(route('orders.index'))
            ->assertOk()
            ->assertJsonPath('data.0.order_items.0.price_list_id', OrderScenario::PRICE_LIST);
    });

    it('prices an order for a secondary branch with the price lists of the buyer', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);
        $product = $scenario->addProductToCart(1000, 2);
        Price::factory()->create([
            'product_id' => $product->id,
            'price_list_id' => 'SEC',
            'unit' => 'kg',
            'price' => 800,
        ]);
        $branch = $scenario->makeSecondaryBranch(['prices_lists' => ['SEC']]);
        $mockWebpay();

        $pay($branch)->assertOk();

        $order = Order::first();
        $item = $order->orderDetails()->first();
        expect($order->customer_id)->toBe($branch->id)
            ->and($order->subtotal)->toBe(2000.0)
            ->and((float) $item->price)->toBe(1000.0)
            ->and($item->price_list_id)->toBe(OrderScenario::PRICE_LIST);
    });

    it('accepts an order for a secondary branch without prices of its own', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);
        $scenario->addProductToCart(1000, 1);
        $branch = $scenario->makeSecondaryBranch(['prices_lists' => []]);
        $mockWebpay();

        $pay($branch)->assertOk();

        expect(Order::first()->customer_id)->toBe($branch->id);
    });

    it('shows in the cart the price the order is charged with', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        $scenario->user->update(['prices_lists' => ['SECOND', OrderScenario::PRICE_LIST]]);
        $scenario->user->givePermissionTo('read-own-cart');
        Sanctum::actingAs($scenario->user, ['api-access']);
        $product = $scenario->addProductToCart(1000, 2);
        Price::factory()->create([
            'product_id' => $product->id,
            'price_list_id' => 'SECOND',
            'unit' => 'kg',
            'price' => 900,
        ]);
        $mockWebpay();

        getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.items.0.price', 900)
            ->assertJsonPath('data.total', 1800);

        $pay($scenario->user)->assertOk();

        expect(Order::first()->subtotal)->toBe(1800.0);
    });

    it('uses the first price list of the user when the product is priced in several', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        $scenario->user->update(['prices_lists' => ['SECOND', OrderScenario::PRICE_LIST]]);
        Sanctum::actingAs($scenario->user, ['api-access']);
        $product = $scenario->addProductToCart(1000, 1);
        Price::factory()->create([
            'product_id' => $product->id,
            'price_list_id' => 'SECOND',
            'unit' => 'kg',
            'price' => 900,
        ]);
        $mockWebpay();

        $pay($scenario->user)->assertOk();

        $item = Order::first()->orderDetails()->first();
        expect((float) $item->price)->toBe(900.0)
            ->and($item->price_list_id)->toBe('SECOND');
    });

    it('rejects the order when a product has no price in the user price lists', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);
        $scenario->addProductToCart(1000, 1);
        $unpriced = $scenario->addProductToCart(500, 1, 'un', 'OTHER');
        $inactive = $scenario->addProductToCart(700, 1);
        Price::where('product_id', $inactive->id)->update(['is_active' => false]);
        $mockWebpay();

        $pay($scenario->user)
            ->assertUnprocessable()
            ->assertJsonPath('products', [
                ['id' => $unpriced->id, 'name' => $unpriced->name, 'sku' => $unpriced->sku, 'unit' => 'un'],
                ['id' => $inactive->id, 'name' => $inactive->name, 'sku' => $inactive->sku, 'unit' => 'kg'],
            ]);

        expect(Order::count())->toBe(0)
            ->and(CartItem::where('user_id', $scenario->user->id)->count())->toBe(3);
    });

    it('rejects the order when the user has no price lists', function () use ($mockWebpay, $pay) {
        $scenario = OrderScenario::make();
        $scenario->user->update(['prices_lists' => []]);
        Sanctum::actingAs($scenario->user, ['api-access']);
        $product = $scenario->addProductToCart(1000, 1);
        $mockWebpay();

        $pay($scenario->user)
            ->assertUnprocessable()
            ->assertJsonPath('products.0.id', $product->id);
    });
});

describe('Order VAT', function () {
    /**
     * Pay a one-product cart and hand back the resulting order.
     */
    $payCart = function (float $price, int $quantity): Order {
        $scenario = OrderScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);
        $scenario->addProductToCart($price, $quantity);

        $address = Address::factory()->create(['user_id' => $scenario->user->id]);

        mock(WebpayService::class, function ($mock) {
            $mock->shouldReceive('createTransaction')
                ->once()
                ->andReturn([
                    'url' => 'https://webpay.test/init',
                    'token' => 'test-token-123',
                ]);
        });

        postJson(route('orders.pay'), [
            'address_id' => $address->id,
            'payment_method' => 'transbank',
            'payment_document_type' => PaymentDocumentType::RECEIPT,
        ])->assertOk();

        return Order::first();
    };

    it('stores the VAT rate and the gross total on the order', function () use ($payCart) {
        $order = $payCart(1000, 2);

        expect($order->subtotal)->toBe(2000.0);
        expect($order->vat)->toBe(19.0);
        expect($order->vat_amount)->toBe(380.0);
        expect($order->total)->toBe(2380.0);
        expect($order->amount)->toBe($order->total + $order->shipping_cost);
    });

    it('stores the subtotal, rate and total of every order item', function () use ($payCart) {
        $order = $payCart(1000, 2);
        $item = $order->orderDetails()->first();

        expect((float) $item->subtotal)->toBe(2000.0);
        expect($item->vat)->toBe(19.0);
        expect((float) $item->total)->toBe(2380.0);
        expect($item->vat_amount)->toBe(380.0);
    });

    it('uses the VAT rate configured in siteinfo', function () use ($payCart) {
        Siteinfo::updateOrCreate(
            ['key' => VatService::SETTINGS_KEY],
            ['value' => ['rate' => 5]]
        );

        $order = $payCart(1000, 2);

        expect($order->vat)->toBe(5.0);
        expect($order->total)->toBe(2100.0);
    });

    it('leaves the total equal to the subtotal when the rate is zero', function () use ($payCart) {
        Siteinfo::updateOrCreate(
            ['key' => VatService::SETTINGS_KEY],
            ['value' => ['rate' => 0]]
        );

        $order = $payCart(1000, 2);

        expect($order->vat)->toBe(0.0);
        expect($order->vat_amount)->toBe(0.0);
        expect($order->total)->toBe($order->subtotal);
    });

    it('exposes the VAT breakdown in the order listing', function () use ($payCart) {
        $payCart(1000, 2);

        $response = getJson(route('orders.index'))->assertOk();

        expect($response->json('data.0.vat'))->toEqual(19);
        expect($response->json('data.0.vat_amount'))->toEqual(380);
        expect($response->json('data.0.total'))->toEqual(2380);
        expect($response->json('data.0.subtotal'))->toEqual(2000);
    });
});
