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
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole('customer');
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
        test('puede listar las órdenes del usuario autenticado', function () {
            // Arrange
            Order::factory()->count(3)->create([
                'user_id' => $this->user->id
            ]);

            // Crear órdenes de otro usuario para verificar que no se incluyan
            $otherUser = User::factory()->create();
            $otherUser->assignRole('customer');
            Order::factory()->count(2)->create([
                'user_id' => $otherUser->id
            ]);

            // Act
            $response = $this->getJson('/api/orders');

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

        test('requiere autenticación para listar órdenes', function () {
            // Arrange
            \Illuminate\Support\Facades\Auth::logout();

            // Act
            $response = $this->getJson('/api/orders');

            // Assert
            $response->assertUnauthorized();
        });
    });

    describe('payOrder', function () {
        test('puede iniciar el pago de una orden desde el carrito', function () {
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
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
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

        test('no puede pagar si el carrito está vacío', function () {
            // Arrange
            $address = Address::factory()->create([
                'user_id' => $this->user->id
            ]);

            // Act
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertBadRequest()
                ->assertJson(['message' => 'El carrito está vacío']);
        });

        test('no puede pagar con una dirección que no le pertenece', function () {
            // Arrange
            createProductCart();
            $otroUsuario = User::factory()->create();
            $otroUsuario->assignRole('customer');
            $address = Address::factory()->create([
                'user_id' => $otroUsuario->id
            ]);

            // Act
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requiere una dirección válida para pagar', function () {
            // Arrange
            createProductCart();

            // Act
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => 999999
            ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requiere el campo address_id', function () {
            // Arrange
            createProductCart();

            // Act
            $response = $this->postJson('/api/orders/pay', []);

            // Assert
            $response->assertStatus(422)
                ->assertJsonValidationErrors('address_id');
        });

        test('requiere autenticación para pagar', function () {
            // Arrange
            \Illuminate\Support\Facades\Auth::logout();
            $address = Address::factory()->create();

            // Act
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertUnauthorized();
        });

        test('maneja errores del servicio de pago', function () {
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
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertStatus(500)
                ->assertJsonStructure([
                    'message',
                    'order'
                ]);
        });

        test('calcula correctamente el subtotal y amount de la orden', function () {
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
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertOk();
            
            $order = Order::first();
            expect($order->subtotal)->toBe(450.0); // 150 * 3
            expect($order->amount)->toBe(450.0);
        });

        test('incluye los metadatos de usuario y dirección en la orden', function () {
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
            $response = $this->postJson('/api/orders/pay', [
                'address_id' => $address->id
            ]);

            // Assert
            $response->assertOk();
            
            $order = Order::first();
            expect($order->order_meta)->toHaveKey('user');
            expect($order->order_meta)->toHaveKey('address');
            expect($order->order_meta['user']['id'])->toBe($this->user->id);
            expect($order->order_meta['address']['id'])->toBe($address->id);
        });
    });
});
