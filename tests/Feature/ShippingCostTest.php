<?php

use App\Models\User;
use App\Models\Order;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\Address;
use App\Services\WebpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['read-own-orders', 'create-orders', 'create-cart-items']);
    $this->actingAs($this->user);
});

function addToCart(int $precio, int $cantidad = 1, string $unidad = 'kg'): void
{
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
    $brand = Brand::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'brand_id' => $brand->id,
    ]);

    Price::factory()->create([
        'product_id' => $product->id,
        'unit' => $unidad,
        'price' => $precio,
        'valid_from' => now()->subDays(1),
        'valid_to' => null,
        'is_active' => true,
    ]);

    CartItem::create([
        'user_id' => \Illuminate\Support\Facades\Auth::id(),
        'product_id' => $product->id,
        'quantity' => $cantidad,
        'price' => $precio,
        'unit' => $unidad,
    ]);
}

function mockWebpayOk($test): void
{
    $test->mock(WebpayService::class, function ($mock) {
        $mock->shouldReceive('createTransaction')
            ->once()
            ->andReturn(['url' => 'https://webpay.test/init', 'token' => 'tok-123']);
    });
}

describe('Shipping cost calculation', function () {

    describe('envío gratis (subtotal >= $70.000)', function () {

        test('shipping es cero cuando subtotal es exactamente el umbral', function () {
            // 35.000 * 2 = 70.000 → envío gratis
            addToCart(35000, 2);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(70000.0)
                ->and($order->shipping_cost)->toBe(0.0)
                ->and($order->amount)->toBe(70000.0);
        });

        test('shipping es cero cuando subtotal supera el umbral', function () {
            // 50.000 * 2 = 100.000 → envío gratis
            addToCart(50000, 2);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(100000.0)
                ->and($order->shipping_cost)->toBe(0.0)
                ->and($order->amount)->toBe(100000.0);
        });

        test('shipping_cost se persiste como cero en la BD', function () {
            addToCart(70000, 1);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $this->assertDatabaseHas('orders', [
                'user_id' => $this->user->id,
                'subtotal' => 70000,
                'shipping_cost' => 0,
                'amount' => 70000,
            ]);
        });
    });

    describe('envío cobrado (subtotal < $70.000)', function () {

        test('shipping es 10% del subtotal', function () {
            // 30.000 * 2 = 60.000 → shipping = 6.000 → total = 66.000
            addToCart(30000, 2);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(60000.0)
                ->and($order->shipping_cost)->toBe(6000.0)
                ->and($order->amount)->toBe(66000.0);
        });

        test('shipping redondea hacia arriba cuando el decimal es >= .5', function () {
            // subtotal = 667 → shipping = round(66.7) = 67 → total = 734
            addToCart(667, 1);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->shipping_cost)->toBe(67.0)
                ->and($order->amount)->toBe(734.0);
        });

        test('shipping redondea hacia abajo cuando el decimal es < .5', function () {
            // subtotal = 333 → shipping = round(33.3) = 33 → total = 366
            addToCart(333, 1);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->shipping_cost)->toBe(33.0)
                ->and($order->amount)->toBe(366.0);
        });

        test('shipping_cost y amount se persisten en la BD', function () {
            // 10.000 * 5 = 50.000 → shipping = 5.000 → total = 55.000
            addToCart(10000, 5);
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $this->assertDatabaseHas('orders', [
                'user_id' => $this->user->id,
                'subtotal' => 50000,
                'shipping_cost' => 5000,
                'amount' => 55000,
            ]);
        });

        test('shipping se calcula sobre el subtotal combinado de varios productos', function () {
            // item 1: 10.000 * 2 = 20.000
            // item 2: 15.000 * 1 = 15.000
            // subtotal = 35.000 → shipping = 3.500 → total = 38.500
            addToCart(10000, 2, 'kg');
            addToCart(15000, 1, 'un');
            $address = Address::factory()->create(['user_id' => $this->user->id]);
            mockWebpayOk($this);

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            $order = Order::first();
            expect($order->subtotal)->toBe(35000.0)
                ->and($order->shipping_cost)->toBe(3500.0)
                ->and($order->amount)->toBe(38500.0);
        });
    });

    describe('Webpay recibe el monto correcto', function () {

        test('Webpay recibe amount que incluye el envío', function () {
            // 20.000 → shipping = 2.000 → amount = 22.000
            addToCart(20000, 1);
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $capturedOrder = null;
            $this->mock(WebpayService::class, function ($mock) use (&$capturedOrder) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->withArgs(function (Order $order) use (&$capturedOrder) {
                        $capturedOrder = $order;
                        return true;
                    })
                    ->andReturn(['url' => 'https://webpay.test/init', 'token' => 'tok-123']);
            });

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            expect($capturedOrder->amount)->toBe(22000.0);
        });

        test('Webpay recibe amount igual al subtotal cuando el envío es gratis', function () {
            // 40.000 * 2 = 80.000 >= 70.000 → shipping = 0 → amount = subtotal
            addToCart(40000, 2);
            $address = Address::factory()->create(['user_id' => $this->user->id]);

            $capturedOrder = null;
            $this->mock(WebpayService::class, function ($mock) use (&$capturedOrder) {
                $mock->shouldReceive('createTransaction')
                    ->once()
                    ->withArgs(function (Order $order) use (&$capturedOrder) {
                        $capturedOrder = $order;
                        return true;
                    })
                    ->andReturn(['url' => 'https://webpay.test/init', 'token' => 'tok-123']);
            });

            $this->postJson(route('orders.pay'), ['address_id' => $address->id])->assertOk();

            expect($capturedOrder->amount)->toBe(80000.0)
                ->and($capturedOrder->shipping_cost)->toBe(0.0);
        });
    });
});
