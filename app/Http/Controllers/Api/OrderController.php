<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderCompleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PayOrderRequest;
use App\Http\Resources\Orders\OrderCollection;
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
use App\Services\Random\RandomDocumentService;
use App\Services\Random\RandomDocumentPayloadBuilder;
use App\Services\CustomerPriceResolver;
use App\Services\PaymentService;
use App\Services\VatService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

#[Group('Orders', 'List the user\'s orders and check out the cart, paying with Webpay or the Random ERP credit line.', weight: 11)]
class OrderController extends Controller
{
    protected WebpayService $webpayService;

    /**
     * @var RandomDocumentService
     */
    protected RandomDocumentService $documentService;

    protected RandomDocumentPayloadBuilder $payloadBuilder;

    protected PaymentService $paymentService;

    protected VatService $vatService;

    protected CustomerPriceResolver $priceResolver;

    public function __construct(
        WebpayService $webpayService,
        RandomDocumentService $randomDocumentService,
        RandomDocumentPayloadBuilder $payloadBuilder,
        PaymentService $paymentService,
        VatService $vatService,
        CustomerPriceResolver $priceResolver,
    ) {
        $this->webpayService = $webpayService;
        $this->documentService = $randomDocumentService;
        $this->payloadBuilder = $payloadBuilder;
        $this->paymentService = $paymentService;
        $this->vatService = $vatService;
        $this->priceResolver = $priceResolver;
    }

    /**
     * List orders
     *
     * Lists the orders placed by the authenticated user and the orders placed for it (by its primary
     * branch), with their items, payments and customer.
     */
    #[QueryParameter('per_page', 'Orders per page.', type: 'int', default: 20)]
    #[QueryParameter('sort', 'Sort field. Other values fall back to `created_at`.', type: "'id'|'created_at'", default: 'created_at')]
    #[QueryParameter('sort_direction', 'Sort direction. Other values fall back to `desc`.', type: "'asc'|'desc'", default: 'desc')]
    #[QueryParameter('payment_method_code', 'Only orders with a payment made with this payment method code.', type: 'string', example: 'random_credit')]
    public function index(Request $request)
    {
        $perPage = $request->input("per_page", 20);
        $sortBy = in_array($request->input("sort"), ["id", "created_at"])
            ? $request->input("sort")
            : "created_at";
        $sortDirection = in_array($request->input("sort_direction"), [
            "asc",
            "desc",
        ])
            ? $request->input("sort_direction")
            : "desc";

        $orders = Order::visibleTo(Auth::user())
            ->with(["payments", "customer", "orderDetails.product"])
            ->when($request->has("payment_method_code"), function (
                Builder $query,
            ) use ($request) {
                $code = $request->input("payment_method_code");
                $query->byPaymentMethodCode($code);
            })
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);

        return new OrderCollection($orders);
    }

    /**
     * Build a pending order out of the authenticated user's cart.
     *
     * The cart only holds product, unit and quantity, so prices, VAT rate and
     * shipping cost are resolved here and frozen on the order: what the customer
     * is charged is what was in force at checkout time, no matter what the price
     * list or the VAT setting do afterwards.
     *
     * Items are priced with the price lists of the customer, since Random prices the
     * sales document with them; the price list of every item is stored on it.
     *
     * @param int $addressId Address of the customer, stored in the order metadata
     * @param User $customer User the order is placed for (the authenticated user or one of its secondary branches)
     * @param string|null $notes Free text notes, forwarded to the ERP document
     *
     * @return \App\Models\Order|\Illuminate\Http\JsonResponse The created order, a 400 response when the cart is empty,
     *                                                         or a 422 response listing the products the customer has no price for
     */
    public function createFromCart(
        int $addressId,
        User $customer,
        ?string $notes = null,
    ) {
        //$this->createCart();
        $carts = CartItem::with("product")
            ->where("user_id", Auth::user()->id)
            ->orderBy("id")
            ->get();

        if ($carts->isEmpty()) {
            return response()->json(
                ["message" => "El carrito está vacío"],
                400,
            );
        }

        $prices = $this->priceResolver->resolve($customer, $carts);
        $unpriced = $carts->filter(fn (CartItem $cart) => $prices->get($cart->id) === null);

        if ($unpriced->isNotEmpty()) {
            return response()->json(
                [
                    "message" => "Algunos productos del carrito no tienen precio para el cliente del pedido",
                    "products" => $unpriced->map(fn (CartItem $cart) => [
                        "id" => $cart->product_id,
                        "name" => $cart->product?->name,
                        "sku" => $cart->product?->sku,
                        "unit" => $cart->unit,
                    ])->values(),
                ],
                422,
            );
        }

        try {
            DB::beginTransaction();

            $lines = $carts->map(fn (CartItem $cart) => [
                "cart" => $cart,
                "price" => $prices->get($cart->id),
                "subtotal" => (float) $prices->get($cart->id)->price * $cart->quantity,
            ]);

            $subtotal = (int) round($lines->sum("subtotal"));
            $vatRate = $this->vatService->rate();
            // VAT is calculated on the net subtotal of the order; the office is
            // sum later, just as it was charged before integrating VAT.
            $total = (int) $this->vatService->applyTo($subtotal, $vatRate, 0);
            $shippingCost =
                $subtotal >= 70000
                ? 0
                : (int) config("random.fixed_shipping_cost");
            $amount = $total + $shippingCost;

            $user = User::find(Auth::user()->id);
            $address = $customer->addresses()->where("id", $addressId)->first();

            $order_meta = [
                "user" => $user->toArray(),
                "address" => $address ? $address->toArray() : null,
            ];

            $data = [
                "user_id" => $user->id,
                "subtotal" => $subtotal,
                "vat" => $vatRate,
                "total" => $total,
                "shipping_cost" => $shippingCost,
                "amount" => $amount,
                "status" => "pending",
                "order_meta" => $order_meta,
                "customer_id" => $customer->id,
                "notes" => $notes ?? "",
            ];

            // Create the order
            $order = Order::create($data);

            // Create the order items
            foreach ($lines as $line) {
                OrderItem::create([
                    "order_id" => $order->id,
                    "product_id" => $line["cart"]->product_id,
                    "unit" => $line["price"]->unit,
                    "quantity" => $line["cart"]->quantity,
                    "price" => $line["price"]->price,
                    "price_list_id" => $line["price"]->price_list_id,
                    "subtotal" => $line["subtotal"],
                    "vat" => $vatRate,
                    "total" => $this->vatService->applyTo(
                        $line["subtotal"],
                        $vatRate,
                    ),
                ]);
            }

            DB::commit();

            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Pay the cart
     *
     * Creates an order out of the authenticated user's cart and starts its payment. The order is placed
     * for `customer_id` (the user itself by default): a primary branch can also order for the active
     * secondary branches of its entity. Items are priced with the customer's price lists (the first list,
     * in the customer's order, that prices the product and unit), and the prices, VAT and shipping cost
     * are frozen on the order. Shipping is free from a net subtotal of 70,000.
     *
     * `payment_method` decides how the order is paid:
     *
     * - `random_credit`: the amount is charged to the customer's Random ERP credit line and the order is
     *   sent to Random ERP right away as a sales note (NVV) issued with the customer's entity and branch
     *   codes. Failures also respond 200, with `success: false` and one of these messages:
     *   - `Línea de crédito bloqueada`: a previous credit order of the customer is not invoiced in
     *     Random ERP yet. The order is left pending.
     *   - `Crédito insuficiente`: the available credit does not cover the amount; `data.credit_status`
     *     has the credit summary from Random ERP. The order is left pending.
     *   - `Creación de nota de venta fallida`: Random ERP rejected the sales note. The order and its
     *     payment are marked failed.
     *
     *   On success the order is completed with its `random_document_number`, the cart is emptied and the
     *   customer's credit line is blocked until Random ERP invoices the sales note.
     * - Any other method pays with Webpay: the response has the pending order and the Transbank
     *   `payment_url` and `token`. Send the buyer to `payment_url` posting the token as `token_ws`;
     *   Transbank sends the buyer back to the configured return URL with `token_ws`, which must be passed
     *   to `GET /webpay/return` to settle the order. The cart is kept until the payment is authorized.
     */
    #[Response(
        200,
        'Webpay transaction created (`data`), or result of the credit payment (`success`)',
        type: 'array{data: \App\Http\Resources\Orders\PaymentResource}'
            . '|array{success: true, data: array{transaction: array{status: "AUTHORIZED"}, payment: \App\Http\Resources\PaymentResource}}'
            . '|array{success: false, message: "Línea de crédito bloqueada", data: array{transaction: array{status: "FAILED"}}}'
            . '|array{success: false, message: "Crédito insuficiente", payment: null, data: array{transaction: array{status: "FAILED"}, payment: null, credit_status: array<string, mixed>}}'
            . '|array{success: false, message: "Creación de nota de venta fallida", data: array{transaction: array{status: "FAILED"}, payment: \App\Http\Resources\PaymentResource, credit_status: array<string, mixed>}}',
    )]
    #[Response(400, 'The cart is empty')]
    #[Response(
        422,
        'Validation error, or some cart products have no price in the customer\'s price lists (`products`)',
        type: 'array{message: string, errors: array<string, list<string>>}'
            . '|array{message: "Algunos productos del carrito no tienen precio para el cliente del pedido", products: list<array{id: int, name: string|null, sku: string|null, unit: string}>}',
    )]
    #[Response(500, 'The Webpay transaction could not be created (the order is left pending), or the credit payment failed unexpectedly')]
    public function payOrder(PayOrderRequest $request)
    {
        $orderInfo = $this->createFromCart(
            intval($request->input("address_id")),
            $request->customer(),
            $request->input("notes", ""),
        );

        if ($orderInfo instanceof Order && $orderInfo->id) {
            if ($orderInfo->status !== "pending") {
                return response()->json(
                    ["message" => "La orden no está pendiente de pago"],
                    400,
                );
            }
            $order = Order::with("customer")->find($orderInfo->id);

            if ($request->payment_method === "random_credit") {
                return $this->processRandomCreditPayment(
                    $order,
                    $request->input("payment_document_type"),
                );
            }

            try {
                $paymentResponse = $this->webpayService->createTransaction(
                    $order,
                    $request->input("payment_document_type"),
                );

                return new PaymentResource(
                    (object) [
                        "order" => $order,
                        "payment_url" => $paymentResponse["url"],
                        "token" => $paymentResponse["token"],
                    ],
                );
            } catch (\Exception $e) {
                return response()->json(
                    [
                        "message" =>
                        "Error al procesar el pago: " . $e->getMessage(),
                        /**
                         * The created order, left pending.
                         *
                         * @var array<string, mixed>
                         */
                        "order" => $order,
                    ],
                    500,
                );
            }
        }

        return $orderInfo; // Devolver la respuesta original si el carrito está vacío
    }

    /**
     * Process payment using Random credit
     *
     * @param Order $order
     * @param string $generateRandomDocType Random Document type to generate in ERP
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function processRandomCreditPayment(
        Order $order,
        string $generateRandomDocType,
    ) {
        $paymentMethod = \App\Models\PaymentMethod::where(
            "code",
            "random_credit",
        )->firstOrFail();

        $randomApiService = app(\App\Services\RandomApiService::class);
        // The credit line is the one of the customer the document is issued for.
        $customer = $order->customer;

        if (
            $customer?->branch_code === null ||
            $customer?->rut === null ||
            $customer?->user_code === null
        ) {
            Log::error("RandomCredit Error: Customer missing required attributes", [
                "customer" => $customer,
            ]);
            // TODO Handle with a custom exception
            throw new \Exception(
                "Random customer doesn't have complete attributes",
            );
        }

        $creditLine = \App\Models\CreditLine::firstOrCreate(
            [
                "user_id" => $customer->id,
                "branch_code" => $customer->branch_code,
            ],
            [
                "is_blocked" => false,
            ],
        );

        if ($creditLine->isBlocked()) {
            return response()->json([
                "success" => false,
                "message" => "Línea de crédito bloqueada",
                "data" => [
                    "transaction" => ["status" => "FAILED"],
                ],
            ]);
        }

        $creditLineResponse = $randomApiService->getCreditLine(
            $customer->user_code,
            $customer->branch_code,
        );

        $creditLineInfo = $creditLineResponse->json();

        $availableCredit = (int) bcsub(
            (string) ($creditLineInfo["CRSD"] ?? 0),
            (string) ($creditLineInfo["CRSDVU"] ?? 0),
            0,
        );

        if ($availableCredit < $order->amount) {
            return response()->json([
                "success" => false,
                "message" => "Crédito insuficiente",
                "payment" => null,
                "data" => [
                    "transaction" => ["status" => "FAILED"],
                    "payment" => null,
                    "credit_status" => $creditLineInfo,
                ],
            ]);
        }

        $payload = $this->payloadBuilder->build(
            $order,
            $generateRandomDocType,
            "Pago a crédito",
        );

        $documentResponse = $this->documentService->createDocument(
            $payload,
            $order,
        );

        if (isset($documentResponse["errorId"])) {
            $payment = $this->paymentService->recordFailedCreditPayment(
                $order,
                $paymentMethod,
            );
            $payment->load("order");
            return response()->json([
                "success" => false,
                "message" => "Creación de nota de venta fallida",
                "data" => [
                    "transaction" => ["status" => "FAILED"],
                    "payment" => new \App\Http\Resources\PaymentResource(
                        $payment,
                    ),
                    "credit_status" => $creditLineInfo,
                ],
            ]);
        }

        $payment = $this->paymentService->recordAuthorizedCreditPayment(
            $order,
            $paymentMethod,
            $generateRandomDocType,
            $documentResponse["numero"],
        );
        OrderCompleted::dispatch($order);
        $localCredit = $creditLineInfo;
        $localCredit["CRSDVU"] = floatval(
            bcadd($creditLineInfo["CRSDVU"], $order->amount),
        );

        $creditLine->update([
            "state" => $localCredit,
            "is_blocked" => true,
        ]);

        $payment->load("order");

        \App\Models\CartItem::where("user_id", $order->user_id)->delete();

        return response()->json(
            [
                "success" => true,
                "data" => [
                    "transaction" => ["status" => "AUTHORIZED"],
                    "payment" => new \App\Http\Resources\PaymentResource(
                        $payment,
                    ),
                ],
            ],
            200,
        );
    }

    //NOTA: No eliminar este método, es para crear un carrito de prueba
    public function createCart()
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create([
            "category_id" => $category->id,
        ]);
        $brand = Brand::factory()->create();

        $product = Product::factory()->create([
            "category_id" => $category->id,
            "subcategory_id" => $subcategory->id,
            "brand_id" => $brand->id,
        ]);

        // Crear productos con sus precios
        $price1 = Price::factory()->create([
            "product_id" => $product->id,
            "price_list_id" => fake()->word(),
            "unit" => "kg",
            "price" => 100,
            "valid_from" => now()->subDays(1),
            "valid_to" => null,
            "is_active" => true,
        ]);

        CartItem::create([
            "user_id" => Auth::user()->id,
            "product_id" => $price1->product_id,
            "quantity" => 2,
            "price" => $price1->price,
            "unit" => $price1->unit,
        ]);
    }
}
