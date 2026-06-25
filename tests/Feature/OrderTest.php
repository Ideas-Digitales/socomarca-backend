<?php

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Address;
use App\Models\Branch;
use App\Enums\PaymentDocumentType;
use App\Enums\BranchType;
use App\Services\WebpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    /** @var TestCase $this */

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['read-own-orders', 'create-orders', 'update-orders', 'create-cart-items']);
    $this->actingAs($this->user);
    $this->branch = Branch::factory()->create(['user_id' => $this->user->id]);
});

function createProductCart($precio = 100, $cantidad = 2, $unidad = 'kg')
{
    $supercategory = Category::factory()->create(['level' => 1]);
    $category = Category::factory()->create(['level' => 2, 'parent_category_id' => $supercategory->id]);
    $subcategory = Category::factory()->create(['level' => 3, 'parent_category_id' => $category->id]);
    $brand = Brand::factory()->create();

    $product = Product::factory()->create([
        'supercategory_id' => $supercategory->id,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'brand_id' => $brand->id
    ]);

    $price = Price::factory()->create([
        'product_id' => $product->id,
        'unit' => $unidad,
        'price' => $precio,
        'valid_from' => now()->subDays(1),
        'valid_to' => null,
        'is_active' => true
    ]);

    CartItem::create([
        'user_id' => \Illuminate\Support\Facades\Auth::id(),
        'product_id' => $product->id,
        'quantity' => $cantidad,
        'price' => $precio,
        'unit' => $unidad,
    ]);
}

describe('OrderController', function () {

    describe('index', function () {
        test('can list authenticated user orders', function () {
            /** @var TestCase $this */

            Order::factory()->count(3)->create([
                'user_id' => $this->user->id
            ]);

            $otherUser = User::factory()->create();
            $otherUser->givePermissionTo(['read-own-orders', 'create-orders']);
            Order::factory()->count(2)->create([
                'user_id' => $otherUser->id
            ]);

            $response = $this->getJson(route('orders.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'user',
                            'subtotal',
                            'amount',
                            'status',
                            'order_items',
                            'order_meta',
                            'created_at',
                            'updated_at'
                        ]
                    ]
                ]);
        });

        test('requires authentication to list orders', function () {
            /** @var TestCase $this */

            \Illuminate\Support\Facades\Auth::logout();

            $response = $this->getJson(route('orders.index'));

            $response->assertUnauthorized();
        });
    });

    describe('payOrder', function () {
        test('can initiate payment for an order from cart', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
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

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'order' => [
                            'id',
                            'user',
                            'subtotal',
                            'amount',
                            'status',
                            'order_items' => [
                                '*' => [
                                    'id',
                                    'product',
                                    'unit',
                                    'quantity',
                                    'price',
                                    'subtotal',
                                    'created_at',
                                    'updated_at'
                                ]
                            ],
                            'order_meta',
                            'created_at',
                            'updated_at'
                        ],
                        'payment_url',
                        'token'
                    ]
                ]);

            $this->assertDatabaseHas('orders', [
                'user_id' => $this->user->id,
                'status'  => 'pending'
            ]);

            $this->assertDatabaseHas('order_items', [
                'product_id' => Product::first()->id,
                'quantity'   => 2,
                'unit'       => 'kg'
            ]);
        });

        test('cannot pay if cart is empty', function () {
            /** @var TestCase $this */

            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertBadRequest()
                ->assertJson(['message' => 'El carrito está vacío']);
        });

        test('cannot pay with an address that does not belong to user', function () {
            /** @var TestCase $this */

            createProductCart();
            $otroUsuario = User::factory()->create();
            $otroUsuario->givePermissionTo(['read-own-orders', 'create-orders']);
            $address = Address::factory()->create([
                'user_id' => $otroUsuario->id
            ]);

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires a valid address to pay', function () {
            /** @var TestCase $this */

            createProductCart();

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => 999999,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires address_id field', function () {
            /** @var TestCase $this */

            createProductCart();

            $response = $this->postJson(route('orders.pay'), []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires authentication to pay', function () {
            /** @var TestCase $this */

            \Illuminate\Support\Facades\Auth::logout();
            $address = Address::factory()->create();

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertUnauthorized();
        });

        test('handles payment service errors', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andThrow(new \Exception('Error de conexión con Webpay'));
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(500)
                ->assertJsonStructure([
                    'message',
                    'order'
                ]);
        });

        test('correctly calculates subtotal and amount', function () {
            /** @var TestCase $this */

            createProductCart(150, 3);
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(450.0);
            expect($order->shipping_cost)->toBe(5990.0);
            expect($order->amount)->toBe(6440.0);
        });

        test('rounds cart subtotal to whole pesos before sending payment amount', function () {
            /** @var TestCase $this */

            createProductCart(1152.5, 3);
            createProductCart(100.4, 1);
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
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

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();
        });

        test('includes user and address metadata in order', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $order = Order::first();
            expect($order->order_meta)->toHaveKey('user');
            expect($order->order_meta)->toHaveKey('address');
            expect($order->order_meta['user']['id'])->toBe($this->user->id);
            expect($order->order_meta['address']['id'])->toBe($address->id);
        });

        test('stores branch_id and notes on order', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
                'notes'                  => 'Leave at the door',
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('orders', [
                'id'        => Order::first()->id,
                'branch_id' => $this->branch->id,
                'notes'     => 'Leave at the door',
            ]);
        });

        test('payment receives generate_random_doc_type via webpay service', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
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

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::INVOICE,
            ]);

            $response->assertOk();
        });

        test('defaults to principal branch when branch_id is omitted', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $principalBranch = Branch::factory()->create([
                'user_id'     => $this->user->id,
                'branch_type' => BranchType::PRIMARY,
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('orders', [
                'id'        => Order::first()->id,
                'branch_id' => $principalBranch->id,
            ]);
        });

        test('notes defaults to empty string when not provided', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url'   => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('orders', [
                'id'    => Order::first()->id,
                'notes' => '',
            ]);
        });
    });

    describe('payOrder validation', function () {
        it('validates branch_id exists', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => 99999,
                'payment_document_type'  => PaymentDocumentType::RECEIPT,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('branch_id');
        });

        it('requires payment_document_type field', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson(route('orders.pay'), [
                'address_id'    => $address->id,
                'payment_method' => 'transbank',
                'branch_id'     => $this->branch->id,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_document_type');
        });

        it('validates payment_document_type is a valid value', function () {
            /** @var TestCase $this */

            createProductCart();
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson(route('orders.pay'), [
                'address_id'             => $address->id,
                'payment_method'         => 'transbank',
                'branch_id'              => $this->branch->id,
                'payment_document_type'  => 'invalid_type',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_document_type');
        });
    });
});
