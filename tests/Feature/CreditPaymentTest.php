<?php

use App\Enums\BranchType;
use App\Listeners\CreateWebpayRandomDocument;
use App\Models\User;
use App\Models\Address;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Branch;
use App\Services\Random\RandomDocumentService;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test("it rejects payment if the user credit line is blocked", function () {
    [$user, $branch] = createCustomerWithBranch();

    \App\Models\CreditLine::factory()->create([
        "user_id" => $user->id,
        "branch_code" => "CM",
        "is_blocked" => true,
    ]);

    $address = Address::factory()->create(["user_id" => $user->id]);
    $product = Product::factory()->create();
    \App\Models\Price::factory()->create([
        "product_id" => $product->id,
        "unit" => "UN",
    ]);

    CartItem::create([
        "user_id" => $user->id,
        "product_id" => $product->id,
        "quantity" => 1,
        "unit" => "UN",
    ]);

    PaymentMethod::where("code", "random_credit")->firstOrFail();

    Sanctum::actingAs($user, ['api-access']);
    $response = postJson(route("orders.pay"), [
        "address_id" => $address->id,
        "payment_method" => "random_credit",
        "branch_id" => $branch->id,
        "payment_document_type" => "receipt",
    ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath("success", false)
        ->assertJsonPath("message", "Línea de crédito bloqueada");
});

test(
    "it calls successfully to Random Credit service during a credit line payment",
    function () {
        /** @var TestCase $this */

        [$user, $branch] = createCustomerWithBranch();

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        $price = \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
                [
                    "KOEN" => "12345678-9",
                    "SUEN" => "CM",
                    "CRSD" => 50092358399999.99,
                    "CRSDVU" => 5915690,
                    "CRSDVV" => 705736,
                    "CRSDCU" => 0,
                    "CRSDCV" => 0,
                ],
                200,
            ),
        ]);
        Sanctum::actingAs($user, ['api-access']);
        getJson(route("users.credit-lines", ["user" => $user->id]))
            ->json();

        $randomDocumentServiceSpy = $this->spy(RandomDocumentService::class);

        Sanctum::actingAs($user, ['api-access']);
        postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        $order = Order::where("user_id", $user->id)->first();
        $orderItems = \App\Models\OrderItem::where(
            "order_id",
            $order->id,
        )->get();
        $lines = $orderItems
            ->map(function ($item) {
                return [
                    "cantidad" => $item->quantity,
                    "codigoProducto" => $item->product->sku,
                ];
            })
            ->toArray();
        $payload = [
            "datos" => [
                "empresa" => config("random.business_code"),
                "codigoEntidad" => $user->user_code,
                "sucursalEntidad" => $branch->code,
                "sucursalEntidadDespacho" => $branch->code,
                "flujoVenta" => "NVVBLV",
                "tido" => "NVV",
                "moneda" => "CLP",
                "modalidad" => config("random.modality"),
                "funcionario" => config("random.functionary"),
                "lineas" => $lines,
                "texto1" => "Pago a crédito. Orden de compra: #{$order->id}",
                "texto2" => "{$order?->user?->rut} - Boleta",
                "texto3" => "Origen: Compra rápida",
                "observacion" => $order->notes,
            ],
        ];

        $randomDocumentServiceSpy
            ->shouldHaveReceived("createDocument")
            ->withArgs(function ($arg) use ($payload) {
                if ($arg instanceof Order) {
                    return true;
                } elseif (is_array($arg)) {
                    $json1 = json_encode($arg);
                    $json2 = json_encode($payload);
                    return $json1 === $json2;
                }

                return false;
            });
    },
);

test(
    "it sends the invoice sale flow to Random ERP when the customer chooses invoice for a credit line payment",
    function () {
        /** @var TestCase $this */

        [$user, $branch] = createCustomerWithBranch();

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
                [
                    "KOEN" => "12345678-9",
                    "SUEN" => "CM",
                    "CRSD" => 50092358399999.99,
                    "CRSDVU" => 5915690,
                    "CRSDVV" => 705736,
                    "CRSDCU" => 0,
                    "CRSDCV" => 0,
                ],
                200,
            ),
            "{$baseUrl}/web32/documento" => Http::response(
                [
                    "numero" => "0000000001",
                    "tido" => "NVV",
                    "idmaeedo" => 657,
                ],
                200,
            ),
        ]);

        Sanctum::actingAs($user, ['api-access']);
        postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "invoice",
        ]);

        Http::assertSent(function (
            \Illuminate\Http\Client\Request $request,
        ) use ($baseUrl, $user) {
            if (
                !str_starts_with($request->url(), "{$baseUrl}/web32/documento")
            ) {
                return false;
            }

            $payload = $request->data();

            return $payload["datos"]["flujoVenta"] === "NVVFCV" &&
                $payload["datos"]["texto2"] === "{$user->rut} - Factura";
        });
    },
);

test("it can process a credit line payment successfully", function () {
    /** @var TestCase $this */

    [$user, $branch] = createCustomerWithBranch();

    $address = Address::factory()->create(["user_id" => $user->id]);
    $product = Product::factory()->create();
    $price = \App\Models\Price::factory()->create([
        "product_id" => $product->id,
        "unit" => "UN",
    ]);

    CartItem::create([
        "user_id" => $user->id,
        "product_id" => $product->id,
        "quantity" => 1,
        "unit" => "UN",
    ]);

    $paymentMethod = PaymentMethod::where(
        "code",
        "random_credit",
    )->firstOrFail();

    $baseUrl = config("random.url");
    Http::fake([
        "{$baseUrl}/login" => Http::response(["token" => "fake_token"], 200),
        "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
            [
                "KOEN" => "12345678-9",
                "SUEN" => "CM",
                "CRSD" => 50092358399999.99,
                "CRSDVU" => 5915690,
                "CRSDVV" => 705736,
                "CRSDCU" => 0,
                "CRSDCV" => 0,
            ],
            200,
        ),
        "{$baseUrl}/web32/documento" => Http::response(
            [
                "numero" => "0000000001",
                "tido" => "NVV",
                "idmaeedo" => 657,
                "uidxmaeedo" => "2CEE468D-35B7-4CBA-A243-E2D20C13C8D7",
                "vabrdo" => 7140,
                "moneda" => "CLP",
                "estado" => [
                    "codigo" => "1",
                    "mensaje" => "Grabación exitosa",
                ],
            ],
            200,
        ),
    ]);

    Sanctum::actingAs($user, ['api-access']);
    $currentCredit = getJson(route("users.credit-lines", ["user" => $user->id]))
        ->json();
    $CRSDVU = $currentCredit["CRSDVU"];

    $response = postJson(route("orders.pay"), [
        "address_id" => $address->id,
        "payment_method" => "random_credit",
        "branch_id" => $branch->id,
        "payment_document_type" => "receipt",
    ]);

    $order = Order::where("user_id", $user->id)->first();

    $response
        ->assertStatus(200)
        ->assertJsonPath("success", true)
        ->assertJsonStructure([
            "data" => [
                "transaction" => ["status"],
                "payment" => [
                    "auth_code",
                    "amount",
                    "response_status",
                    "token",
                    "paid_at",
                    "payment_method",
                    "order",
                ],
            ],
        ])
        ->assertJsonPath("data.transaction.status", "AUTHORIZED")
        ->assertJsonPath("data.payment.response_status", "AUTHORIZED");

    $CRSDVU = bcadd(
        strval($CRSDVU),
        strval($response->json("data.payment.amount")),
    );

    expect($response->json("data.payment.amount"))->toEqual($order->amount);

    expect($order->status)->toBe("completed");

    $payment = $order->payments()->first();
    expect($payment->response_status)->toBe("AUTHORIZED");
    expect($payment->payment_method_id)->toBe($paymentMethod->id);

    expect(CartItem::where("user_id", $user->id)->count())->toBe(0);

    $creditLine = \App\Models\CreditLine::where("user_id", $user->id)->first();
    expect($creditLine)->not->toBeNull();
    expect($creditLine->isBlocked())->toBeTrue();
    expect($creditLine->state["CRSDVU"] == $CRSDVU)->toBeTrue();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use (
        $baseUrl,
        $user,
        $product,
        $branch,
    ) {
        if (!str_starts_with($request->url(), "{$baseUrl}/web32/documento")) {
            return false;
        }

        $payload = $request->data();

        return isset($payload["datos"]) &&
            $payload["datos"]["codigoEntidad"] === $user->user_code &&
            $payload["datos"]["sucursalEntidad"] === $branch->code &&
            $payload["datos"]["tido"] === "NVV" &&
            count($payload["datos"]["lineas"]) === 1 &&
            $payload["datos"]["lineas"][0]["codigoProducto"] ===
            $product->sku &&
            $payload["datos"]["lineas"][0]["cantidad"] === 1;
    });

    expect($order->randomDocuments()->count())->toBe(1);
    expect($order->randomDocuments()->first()->idmaeedo)->toBe(657);
    expect($order->random_document_number)->toBe("0000000001");
    expect($payment->status)->toBe("processing");

    $response = getJson(
        route("orders.index", [
            "payment_method_code" => "random_credit",
        ]),
    );

    expect($response->json("data.0.payments.0.auth_code"))->toBe(
        $payment->auth_code,
    );
    expect($response->json("data.0.payments.0.amount"))->toBe($payment->amount);
    expect($response->json("data.0.payments.0.response_status"))->toBe(
        $payment->response_status,
    );
    expect($response->json("data.0.payments.0.payment_method.code"))->toBe(
        "random_credit",
    );

    $response = getJson(route("orders.index"));

    expect($response->json("data.0.payments.0.auth_code"))->toBe(
        $payment->auth_code,
    );
    expect($response->json("data.0.payments.0.amount"))->toBe($payment->amount);
    expect($response->json("data.0.payments.0.response_status"))->toBe(
        $payment->response_status,
    );
    expect($response->json("data.0.payments.0.payment_method.code"))->toBe(
        "random_credit",
    );

    $response = getJson(
        route("orders.index", [
            "payment_method_code" => "transbank",
        ]),
    );

    expect($response->json("data"))->toBeEmpty();
});

test(
    "it can process a credit line payment successfully when choosing primary branch",
    function () {
        /** @var TestCase $this */

        [$user] = createCustomerWithBranch();
        $branch = Branch::factory()->create([
            "user_id" => $user->id,
            "branch_type" => BranchType::PRIMARY,
        ]);

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $paymentMethod = PaymentMethod::where(
            "code",
            "random_credit",
        )->firstOrFail();

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
                [
                    "KOEN" => "12345678-9",
                    "SUEN" => "CM",
                    "CRSD" => 50092358399999.99,
                    "CRSDVU" => 5915690,
                    "CRSDVV" => 705736,
                    "CRSDCU" => 0,
                    "CRSDCV" => 0,
                ],
                200,
            ),
            "{$baseUrl}/web32/documento" => Http::response(
                [
                    "numero" => "0000000001",
                    "tido" => "NVV",
                    "idmaeedo" => 657,
                    "uidxmaeedo" => "2CEE468D-35B7-4CBA-A243-E2D20C13C8D7",
                    "vabrdo" => 7140,
                    "moneda" => "CLP",
                    "estado" => [
                        "codigo" => "1",
                        "mensaje" => "Grabación exitosa",
                    ],
                ],
                200,
            ),
        ]);

        Sanctum::actingAs($user, ['api-access']);
        $currentCredit = getJson(route("users.credit-lines", ["user" => $user->id]))
            ->json();
        $CRSDVU = $currentCredit["CRSDVU"];

        $response = postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        $order = Order::where("user_id", $user->id)->first();

        $response
            ->assertStatus(200)
            ->assertJsonPath("success", true)
            ->assertJsonStructure([
                "data" => [
                    "transaction" => ["status"],
                    "payment" => [
                        "auth_code",
                        "amount",
                        "response_status",
                        "token",
                        "paid_at",
                        "payment_method",
                        "order",
                    ],
                ],
            ])
            ->assertJsonPath("data.transaction.status", "AUTHORIZED")
            ->assertJsonPath("data.payment.response_status", "AUTHORIZED");

        $CRSDVU = bcadd(
            strval($CRSDVU),
            strval($response->json("data.payment.amount")),
        );

        expect($response->json("data.payment.amount"))->toEqual($order->amount);

        expect($order->status)->toBe("completed");

        $payment = $order->payments()->first();
        expect($payment->response_status)->toBe("AUTHORIZED");
        expect($payment->payment_method_id)->toBe($paymentMethod->id);

        expect(CartItem::where("user_id", $user->id)->count())->toBe(0);

        $creditLine = \App\Models\CreditLine::where(
            "user_id",
            $user->id,
        )->first();
        expect($creditLine)->not->toBeNull();
        expect($creditLine->isBlocked())->toBeTrue();
        expect($creditLine->state["CRSDVU"] == $CRSDVU)->toBeTrue();

        Http::assertSent(function (
            \Illuminate\Http\Client\Request $request,
        ) use ($baseUrl, $user, $product, $branch) {
            if (
                !str_starts_with($request->url(), "{$baseUrl}/web32/documento")
            ) {
                return false;
            }

            $payload = $request->data();

            return isset($payload["datos"]) &&
                $payload["datos"]["codigoEntidad"] === $user->user_code &&
                $payload["datos"]["sucursalEntidad"] === $branch->code &&
                $payload["datos"]["tido"] === "NVV" &&
                count($payload["datos"]["lineas"]) === 1 &&
                $payload["datos"]["lineas"][0]["codigoProducto"] ===
                $product->sku &&
                $payload["datos"]["lineas"][0]["cantidad"] === 1;
        });

        expect($order->randomDocuments()->count())->toBe(1);
        expect($order->randomDocuments()->first()->idmaeedo)->toBe(657);
        expect($order->random_document_number)->toBe("0000000001");
        expect(
            $order
                ->branch()
                ->withoutGlobalScope(
                    \App\Models\Scopes\SecondaryBranchesScope::class,
                )
                ->first()->id,
        )->toBe($branch->id); // ¡IMPORTANT!
        expect($payment->status)->toBe("processing");

        $response = getJson(
            route("orders.index", [
                "payment_method_code" => "random_credit",
            ]),
        );

        expect($response->json("data.0.payments.0.auth_code"))->toBe(
            $payment->auth_code,
        );
        expect($response->json("data.0.payments.0.amount"))->toBe(
            $payment->amount,
        );
        expect($response->json("data.0.payments.0.response_status"))->toBe(
            $payment->response_status,
        );
        expect($response->json("data.0.payments.0.payment_method.code"))->toBe(
            "random_credit",
        );

        $response = getJson(route("orders.index"));

        expect($response->json("data.0.payments.0.auth_code"))->toBe(
            $payment->auth_code,
        );
        expect($response->json("data.0.payments.0.amount"))->toBe(
            $payment->amount,
        );
        expect($response->json("data.0.payments.0.response_status"))->toBe(
            $payment->response_status,
        );
        expect($response->json("data.0.payments.0.payment_method.code"))->toBe(
            "random_credit",
        );

        $response = getJson(
            route("orders.index", [
                "payment_method_code" => "transbank",
            ]),
        );

        expect($response->json("data"))->toBeEmpty();
    },
);
test("it handles credit line payment failure correctly", function () {
    /** @var TestCase $this */

    [$user, $branch] = createCustomerWithBranch();

    $address = Address::factory()->create(["user_id" => $user->id]);
    $product = Product::factory()->create();
    $price = \App\Models\Price::factory()->create([
        "product_id" => $product->id,
        "unit" => "UN",
    ]);

    CartItem::create([
        "user_id" => $user->id,
        "product_id" => $product->id,
        "quantity" => 1,
        "unit" => "UN",
    ]);

    $paymentMethod = PaymentMethod::where(
        "code",
        "random_credit",
    )->firstOrFail();

    $baseUrl = config("random.url");
    Http::fake([
        "{$baseUrl}/login" => Http::response(["token" => "fake_token"], 200),
        "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
            [
                "KOEN" => "12345678-9",
                "SUEN" => "CM",
                "CRSD" => 50092358399999.99,
                "CRSDVU" => 5915690,
                "CRSDVV" => 705736,
                "CRSDCU" => 0,
                "CRSDCV" => 0,
            ],
            200,
        ),
        "{$baseUrl}/web32/documento" => Http::response(
            [
                "message" =>
                "5B8F5331-D5AF-4C80-A1E8-E7C9E096150A| El funcionario del documento no es válido",
                "errorId" => "3YiKmHn_",
            ],
            200,
        ),
    ]);

    Sanctum::actingAs($user, ['api-access']);
    $response = postJson(route("orders.pay"), [
        "address_id" => $address->id,
        "payment_method" => "random_credit",
        "branch_id" => $branch->id,
        "payment_document_type" => "receipt",
    ]);

    $response
        ->assertStatus(200)
        ->assertJsonPath("success", false)
        ->assertJsonPath("data.transaction.status", "FAILED");

    $order = Order::where("user_id", $user->id)->first();
    expect($order->status)->toBe("failed");

    $payment = $order->payments()->first();
    expect($payment->response_status)->toBe("FAILED");
    expect($payment->payment_method_id)->toBe($paymentMethod->id);
});

test(
    "it responds with 500 when Random api returns invalid credit info",
    function () {
        /** @var TestCase $this */

        [$user, $branch] = createCustomerWithBranch();

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        $price = \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
                [
                    "KOEN" => "12345678-9",
                ],
                200,
            ),
        ]);

        Sanctum::actingAs($user, ['api-access']);
        $response = postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        $response->assertStatus(500)->assertJsonFragment([
            "message" => "No se pudo obtener el crédito del cliente",
        ]);
    },
);

test(
    "it fails when the random_credit payment method does not exist in the database",
    function () {
        /** @var TestCase $this */

        [$user, $branch] = createCustomerWithBranch();

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        \App\Models\PaymentMethod::where("code", "random_credit")->delete();

        Sanctum::actingAs($user, ['api-access']);
        $response = postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(["payment_method"]);
    },
);

test(
    "it returns insufficient credit message if order amount exceeds available random credit",
    function () {
        /** @var TestCase $this */

        $user = User::factory()->create([
            "rut" => "9876543-2",
            "user_code" => "9876543-2",
            "branch_code" => "VALPO",
        ]);
        if (!$user->hasRole("customer")) {
            $user->assignRole("customer");
        }

        $branch = Branch::factory()->create([
            "user_id" => $user->id,
            "code" => "VALPO",
            "user_code" => "9876543-2",
        ]);

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        $price = \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
            "price" => 500000,
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/{$user->user_code}/{$user->branch_code}" => Http::response(
                [
                    "KOEN" => $user->rut,
                    "SUEN" => $user->branch_code,
                    "CRSD" => 2000,
                    "CRSDVU" => 1500,
                    "CRSDVV" => 0,
                    "CRSDCU" => 0,
                    "CRSDCV" => 0,
                ],
                200,
            ),
        ]);

        Sanctum::actingAs($user, ['api-access']);
        $response = postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        $response->assertStatus(200)->assertJson([
            "success" => false,
            "message" => "Crédito insuficiente",
            "data" => [
                "transaction" => ["status" => "FAILED"],
            ],
        ]);
    },
);

test(
    "it never queues the webpay random document listener for a credit line payment",
    function () {
        /** @var TestCase $this */
        Queue::fake();

        [$user, $branch] = createCustomerWithBranch();

        $address = Address::factory()->create(["user_id" => $user->id]);
        $product = Product::factory()->create();
        \App\Models\Price::factory()->create([
            "product_id" => $product->id,
            "unit" => "UN",
        ]);

        CartItem::create([
            "user_id" => $user->id,
            "product_id" => $product->id,
            "quantity" => 1,
            "unit" => "UN",
        ]);

        $baseUrl = config("random.url");
        Http::fake([
            "{$baseUrl}/login" => Http::response(
                ["token" => "fake_token"],
                200,
            ),
            "{$baseUrl}/gestion/credito/resumen/12345678-9/CM" => Http::response(
                [
                    "KOEN" => "12345678-9",
                    "SUEN" => "CM",
                    "CRSD" => 50092358399999.99,
                    "CRSDVU" => 5915690,
                    "CRSDVV" => 705736,
                    "CRSDCU" => 0,
                    "CRSDCV" => 0,
                ],
                200,
            ),
            "{$baseUrl}/web32/documento" => Http::response(
                [
                    "numero" => "0000000001",
                    "tido" => "NVV",
                    "idmaeedo" => 657,
                ],
                200,
            ),
        ]);

        Sanctum::actingAs($user, ['api-access']);
        postJson(route("orders.pay"), [
            "address_id" => $address->id,
            "payment_method" => "random_credit",
            "branch_id" => $branch->id,
            "payment_document_type" => "receipt",
        ]);

        Queue::assertNotPushed(CallQueuedListener::class, function ($job) {
            return $job->class === CreateWebpayRandomDocument::class;
        });
    },
);
