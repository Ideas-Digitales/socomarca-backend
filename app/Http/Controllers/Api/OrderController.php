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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
            ->with(['payments'])
            ->when(
                $request->has('payment_method_code'),
                function (Builder $query) use ($request) {
                    $code = $request->input('payment_method_code');
                    $query->byPaymentMethodCode($code);
                }
            )
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

            $shippingCost = $subtotal >= 70000 ? 0 : (int) round($subtotal * 0.1);
            $total = $subtotal + $shippingCost;

            $user = User::find(Auth::user()->id);
            $address = $user->addresses()->where('id', $addressId)->first();

            $order_meta = [
                'user' => $user->toArray(),
                'address' => $address ? $address->toArray() : null,
            ];

            $data = [
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'amount' => $total,
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

            if ($request->payment_method === 'random_credit') {
                return $this->processRandomCreditPayment($order);
            }

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


    private function processRandomCreditPayment(Order $order)
    {
        $paymentMethod = \App\Models\PaymentMethod::where('code', 'random_credit')->firstOrFail();

        $randomApiService = app(\App\Services\RandomApiService::class);
        $user = $order->user;

        if ($user?->branch_code === null || $user?->rut === null) {
            Log::error(
                'RandomCredit Error: User missing required attributes',
                ['user' => $user]
            );
            // TODO Handle with a custom exception
            throw new \Exception("Random customer doesn't have complete attributes");
        }

        $creditLine = \App\Models\CreditLine::firstOrCreate(
            [
                'user_id' => $user->id,
                'branch_code' => $user->branch_code,
            ],
            [
                'is_blocked' => false,
            ]
        );

        if ($creditLine->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Línea de crédito bloqueada',
                'data' => [
                    'transaction' => ['status' => 'FAILED'],
                ]
            ]);
        }

        $creditLineResponse = $randomApiService
            ->getCreditLine($user->rut, $user->branch_code);

        $creditLineInfo = $creditLineResponse->json();

        $creditLine->update(['state' => $creditLineInfo]);

        $availableCredit = (int) bcsub(
            (string) ($creditLineInfo['CRSD'] ?? 0),
            (string) ($creditLineInfo['CRSDVU'] ?? 0),
            0
        );

        if ($availableCredit < $order->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Crédito insuficiente',
                'payment' => null,
                'data' => [
                    'transaction' => ['status' => 'FAILED'],
                    'payment' => null,
                    'credit_status' => $creditLineInfo
                ]
            ]);
        }

        $orderItems = \App\Models\OrderItem::where('order_id', $order->id)->get();
        $lines = $orderItems->map(function ($item) {
            return [
                'cantidad' => $item->quantity,
                'codigoProducto' => $item->product->sku
            ];
        })->toArray();

        $payload = [
            'datos' => [
                'empresa' => config('random.business_code'),
                'codigoEntidad' => $user->rut,
                'tido' => 'NVV',
                'modalidad' => config('random.modality'),
                'lineas' => $lines
            ]
        ];

        $responseObject = $randomApiService->createDocument($payload);
        $response = $responseObject->json();

        // TODO Procesar NVV

        if (isset($response['errorId'])) {
            $order->update(['status' => 'failed']);
            $payment = $order->payments()->create([
                'payment_method_id' => $paymentMethod->id,
                'status' => 'failed',
                'response_status' => 'FAILED',
                'response_message' => [
                    'message' => 'Error al crear NVV en Random ERP'
                ],
                'token' => uniqid(),
                'amount' => $order->amount
            ]);
            $payment->load('order');
            return response()->json([
                'success' => false,
                'message' => 'Creación de nota de venta fallida',
                'data' => [
                    'transaction' => ['status' => 'FAILED'],
                    'payment' => new \App\Http\Resources\PaymentResource($payment),
                    'credit_status' => $creditLineInfo
                ]
            ]);
        }

        $order->update(['status' => 'completed']);
        $payment = $order->payments()->create([
            'payment_method_id' => $paymentMethod->id,
            'status' => 'processing',
            'response_status' => 'AUTHORIZED',
            'auth_code' => uniqid(),
            'amount' => $order->amount,
            'response_message' => ['message' => 'Aprobado'],
            'token' => uniqid(),
            'paid_at' => now()
        ]);

        $idmaeedo = $response['idmaeedo'] ?? null;
        if ($idmaeedo) {
            $randomDocument = \App\Models\RandomDocument::firstOrCreate(
                ['idmaeedo' => $idmaeedo],
                [
                    'type' => 'NVV',
                    'document' => $response
                ]
            );
            $order->randomDocuments()->attach($randomDocument->idmaeedo);
        }

        $creditLine->block();

        $payment->load('order');

        \App\Models\CartItem::where('user_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => ['status' => 'AUTHORIZED'],
                'payment' => new \App\Http\Resources\PaymentResource($payment)
            ]
        ], 200);
    }

    //NOTA: No eliminar este método, es para crear un carrito de prueba
    public function createCart()
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
