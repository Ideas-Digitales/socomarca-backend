<?php

namespace App\Http\Controllers\Api;

use App\Enums\BranchType;
use App\Events\OrderCompleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PayOrderRequest;
use App\Http\Resources\Orders\OrderCollection;
use App\Http\Resources\Orders\PaymentResource;
use App\Models\Branch;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Scopes\SecondaryBranchesScope;
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
use App\Services\PaymentService;
use App\Services\VatService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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

    public function __construct(
        WebpayService $webpayService,
        RandomDocumentService $randomDocumentService,
        RandomDocumentPayloadBuilder $payloadBuilder,
        PaymentService $paymentService,
        VatService $vatService,
    ) {
        $this->webpayService = $webpayService;
        $this->documentService = $randomDocumentService;
        $this->payloadBuilder = $payloadBuilder;
        $this->paymentService = $paymentService;
        $this->vatService = $vatService;
    }

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

        $orders = Order::where("user_id", Auth::user()->id)
            ->with(["payments", "branch"])
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
     * @param int $addressId Address stored in the order metadata
     * @param int $branchId Branch the order belongs to; falls back to the user's primary branch when 0
     * @param string|null $notes Free text notes, forwarded to the ERP document
     *
     * @return \App\Models\Order|\Illuminate\Http\JsonResponse The created order, or a 400 response when the cart is empty
     */
    public function createFromCart(
        int $addressId,
        int $branchId,
        ?string $notes = null,
    ) {
        //$this->createCart();
        $carts = CartItem::where("user_id", Auth::user()->id)->get();

        if ($carts->isEmpty()) {
            return response()->json(
                ["message" => "El carrito está vacío"],
                400,
            );
        }

        try {
            DB::beginTransaction();

            // Calculate totals
            $subtotal = $carts->sum(function ($cart) {
                $price = $cart->product->prices
                    ->where("unit", $cart->unit)
                    ->first();
                return $price->price * $cart->quantity;
            });

            $subtotal = (int) round($subtotal);
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
            $address = $user->addresses()->where("id", $addressId)->first();

            if (!$branchId) {
                $branchId = Branch::withoutGlobalScope(
                    SecondaryBranchesScope::class,
                )
                    ->where("user_id", $user->id)
                    ->where("branch_type", BranchType::PRIMARY)
                    ->value("id");
            }

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
                "branch_id" => $branchId,
                "notes" => $notes ?? "",
            ];

            // Create the order
            $order = Order::create($data);

            // Create the order items
            foreach ($carts as $cart) {
                $price = $cart->product->prices
                    ->where("unit", $cart->unit)
                    ->first();
                $lineSubtotal = (float) ($price->price ?? 0) * $cart->quantity;

                OrderItem::create([
                    "order_id" => $order->id,
                    "product_id" => $cart->product_id,
                    "unit" => $price->unit,
                    "quantity" => $cart->quantity,
                    "price" => $price->price ?? 0,
                    "subtotal" => $lineSubtotal,
                    "vat" => $vatRate,
                    "total" => $this->vatService->applyTo(
                        $lineSubtotal,
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

    public function payOrder(PayOrderRequest $request)
    {
        $orderInfo = $this->createFromCart(
            intval($request->input("address_id")),
            intval($request->input("branch_id")),
            $request->input("notes", ""),
        );

        if ($orderInfo instanceof Order && $orderInfo->id) {
            if ($orderInfo->status !== "pending") {
                return response()->json(
                    ["message" => "La orden no está pendiente de pago"],
                    400,
                );
            }
            $order = Order::find($orderInfo->id);

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
        $user = $order->user;

        if (
            $user?->branch_code === null ||
            $user?->rut === null ||
            $user?->user_code === null
        ) {
            Log::error("RandomCredit Error: User missing required attributes", [
                "user" => $user,
            ]);
            // TODO Handle with a custom exception
            throw new \Exception(
                "Random customer doesn't have complete attributes",
            );
        }

        $creditLine = \App\Models\CreditLine::firstOrCreate(
            [
                "user_id" => $user->id,
                "branch_code" => $user->branch_code,
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
            $user->user_code,
            $user->branch_code,
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

        \App\Models\CartItem::where("user_id", $user->id)->delete();

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
