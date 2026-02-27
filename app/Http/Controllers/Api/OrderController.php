<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PayOrderRequest;
use App\Http\Resources\Orders\OrderCollection;
use App\Http\Resources\Orders\OrderResource;
use App\Http\Resources\Orders\PaymentResource;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Services\WebpayService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\Price;

class OrderController extends Controller
{
    protected $webpayService;

    public function __construct(WebpayService $webpayService)
    {
        $this->webpayService = $webpayService;
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $sortBy = in_array($request->input('sort'), ['id', 'created_at']) ? $request->input('sort') : 'created_at';
        $sortDirection = in_array($request->input('sort_direction'), ['asc', 'desc']) ? $request->input('sort_direction') : 'desc';

        $orders = Order::where('user_id', Auth::user()->id)
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
        return new OrderCollection($orders);
    }

    public function createFromCart($addressId)
    {

        //$this->createCart();
        $carts = CartItem::where('user_id', Auth::user()->id)->get();

        if ($carts->isEmpty()) {
            return response()->json(['message' => 'El carrito está vacío'], 400);
        }

        try {
            DB::beginTransaction();

            // Calcular totales
            $subtotal = $carts->sum(function ($cart) {
                $price = $cart->product->prices->where('unit', $cart->unit)->first();
                return $price->price * $cart->quantity;
            });

            $user = User::find(Auth::user()->id);
            $address = $user->addresses()->where('id', $addressId)->first();

            $order_meta = [
                'user' => $user->toArray(),
                'address' => $address ? $address->toArray() : null,
            ];

            $data = [
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'amount' => $subtotal,
                'status' => 'pending',
                'order_meta' => $order_meta,
            ];

            // Crear la orden
            $order = Order::create($data);

            // Crear los items de la orden
            foreach ($carts as $cart) {
                $price = $cart->product->prices->where('unit', $cart->unit)->first();
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cart->product_id,
                    'unit' => $price->unit,
                    'quantity' => $cart->quantity,
                    'price' => $price->price ?? 0
                ]);
            }

            DB::commit();

            return $order;

            return new OrderResource($order);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function payOrder(PayOrderRequest $request)
    {
        $orderInfo = $this->createFromCart($request->input('address_id'));

        if ($orderInfo instanceof Order && $orderInfo->id) {
            if ($orderInfo->status !== 'pending') {
                return response()->json(['message' => 'La orden no está pendiente de pago'], 400);
            }
            $order = Order::find($orderInfo->id);

            try {
                $paymentResponse = $this->webpayService->createTransaction($order);

                return new PaymentResource((object)[
                    'order' => $order,
                    'payment_url' => $paymentResponse['url'],
                    'token' => $paymentResponse['token']
                ]);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Error al procesar el pago: ' . $e->getMessage(), 'order' => $order], 500);
            }
        }

        return $orderInfo; // Devolver la respuesta original si el carrito está vacío
    }


    //NOTA: No eliminar este método, es para crear un carrito de prueba
    public function createCart(){
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

        // Crear productos con sus precios
        $price1 = Price::factory()->create([
            'product_id' => $product->id,
            'price_list_id' => fake()->word(),
            'unit' => 'kg',
            'price' => 100,
            'valid_from' => now()->subDays(1),
            'valid_to' => null,
            'is_active' => true
        ]);

        CartItem::create([
            'user_id' => Auth::user()->id,
            'product_id' => $price1->product_id,
            'quantity' => 2,
            'price' => $price1->price,
            'unit' => $price1->unit,
        ]);
    }
}
