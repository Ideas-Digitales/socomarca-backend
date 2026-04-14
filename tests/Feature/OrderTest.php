<?php

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Region;
use App\Models\Municipality;
use App\Models\Address;
use App\Services\WebpayService;
use App\Services\RandomApiService;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['read-own-orders', 'create-orders', 'update-orders', 'create-cart-items']);
    $this->actingAs($this->user);
});

// Función helper para crear productos en el carrito
function createProductCart($precio = 100, $cantidad = 2, $unidad = 'kg')
{
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->create([
        'category_id' => $category->id
    ]);
    $brand = Brand::factory()->create();

    $product = Product::factory()->create([
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
            // Arrange
            Order::factory()->count(3)->create([
                'user_id' => $this->user->id
            ]);

            // Crear órdenes de otro usuario para verificar que no se incluyan
            $otherUser = User::factory()->create();
            $otherUser->givePermissionTo(['read-own-orders', 'create-orders']);
            Order::factory()->count(2)->create([
                'user_id' => $otherUser->id
            ]);

            // Act
            $response = $this->getJson(route('orders.index'));

            // Assert
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
            // Arrange
            \Illuminate\Support\Facades\Auth::logout();

            // Act
            $response = $this->getJson(route('orders.index'));

            // Assert
            $response->assertUnauthorized();
        });
    });

    describe('payOrder', function () {
        test('can initiate payment for an order from cart', function () {
            // Arrange
            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            // Mock del servicio de Webpay
            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url' => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'webpay',
            ]);

            // Assert
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

            // Verificar que se creó la orden
            $this->assertDatabaseHas('orders', [
                'user_id' => $this->user->id,
                'status' => 'pending'
            ]);

            // Verificar que se crearon los items de la orden
            $this->assertDatabaseHas('order_items', [
                'product_id' => Product::first()->id,
                'quantity' => 2,
                'unit' => 'kg'
            ]);
        });

        test('cannot pay if cart is empty', function () {
            // Arrange
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'webpay',
            ]);

            // Assert
            $response->assertBadRequest()
                ->assertJson(['message' => 'El carrito está vacío']);
        });

        test('cannot pay with an address that does not belong to user', function () {
            // Arrange
            createProductCart();
            $otroUsuario = User::factory()->create();
            $otroUsuario->givePermissionTo(['read-own-orders', 'create-orders']);
            $address = Address::factory()->create([
                'user_id' => $otroUsuario->id
            ]);

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'webpay',
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires a valid address to pay', function () {
            // Arrange
            createProductCart();

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => 999999,
                'payment_method' => 'webpay',
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires address_id field', function () {
            // Arrange
            createProductCart();

            // Act
            $response = $this->postJson(route('orders.pay'), []);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requires authentication to pay', function () {
            // Arrange
            \Illuminate\Support\Facades\Auth::logout();
            $address = Address::factory()->create();

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertUnauthorized();
        });

        test('handles payment service errors', function () {
            // Arrange
            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            // Mock del servicio de Webpay para que falle
            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andThrow(new \Exception('Error de conexión con Webpay'));
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'webpay',
            ]);

            // Assert
            $response->assertStatus(500)
                ->assertJsonStructure([
                    'message',
                    'order'
                ]);
        });

        test('correctly calculates subtotal and amount', function () {
            // Arrange
            createProductCart(150, 3); // precio 150, cantidad 3
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url' => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(450.0); // 150 * 3
            expect($order->amount)->toBe(450.0);
        });

        test('includes user and address metadata in order', function () {
            // Arrange
            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            $this->mock(WebpayService::class, function ($mock) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->andReturn([
                        'url' => 'https://webpay.test/init',
                        'token' => 'test-token-123'
                    ]);
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'webpay',
            ]);

            // Assert
            $response->assertOk();

            $order = Order::first();
            expect($order->order_meta)->toHaveKey('user');
            expect($order->order_meta)->toHaveKey('address');
            expect($order->order_meta['user']['id'])->toBe($this->user->id);
            expect($order->order_meta['address']['id'])->toBe($address->id);
        });

        test('can pay with credit line when credit is sufficient', function () {
            // Arrange
            createProductCart(100, 2); // total = 200
            $address = Address::factory()->create([
                'user_id' => $this->user->id,
            ]);
            $this->user->update(['rut' => '12345678-9', 'sucursal_code' => 'SUC01']);

            $this->mock(RandomApiService::class, function ($mock) {
                $mock->shouldReceive('getCreditLine')
                    ->once()
                    ->andReturn(['credito_disponible' => 5000]); // crédito suficiente
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'credit_line',
            ]);

            // Assert
            $response->assertOk()
                ->assertJsonFragment(['payment_method' => 'credit_line'])
                ->assertJsonFragment(['message' => 'Orden pagada con línea de crédito.']);

            $this->assertDatabaseHas('orders', [
                'user_id' => $this->user->id,
                'status'  => 'paid',
            ]);
        });

        test('cannot pay with credit line when credit is insufficient', function () {
            // Arrange
            createProductCart(500, 3); // total = 1500
            $address = Address::factory()->create([
                'user_id' => $this->user->id,
            ]);
            $this->user->update(['rut' => '12345678-9', 'sucursal_code' => 'SUC01']);

            $this->mock(RandomApiService::class, function ($mock) {
                $mock->shouldReceive('getCreditLine')
                    ->once()
                    ->andReturn(['credito_disponible' => 100]); // crédito insuficiente
            });

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'credit_line',
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonFragment(['message' => 'Crédito disponible insuficiente para cubrir el monto de la orden.']);

            // La orden debe haber sido eliminada al no poder pagarse
            $this->assertDatabaseMissing('orders', [
                'user_id' => $this->user->id,
                'status'  => 'paid',
            ]);
        });

        test('rejects unknown payment_method', function () {
            // Arrange
            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id,
            ]);

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id'     => $address->id,
                'payment_method' => 'bitcoin',
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_method');
        });

        test('payment_method is required', function () {
            // Arrange
            createProductCart();
            $address = Address::factory()->create([
                'user_id' => $this->user->id,
            ]);

            // Act
            $response = $this->postJson(route('orders.pay'), [
                'address_id' => $address->id,
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('payment_method');
        });
    });
});
