<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test(
    "createPendingWebpayPayment creates a pending payment with the given token",
    function () {
        $order = Order::factory()->create(["amount" => 12345]);
        $paymentMethod = PaymentMethod::where(
            "code",
            "transbank",
        )->firstOrFail();

        $payment = (new PaymentService())->createPendingWebpayPayment(
            $order,
            $paymentMethod,
            "tok_abc",
            "invoice",
        );

        expect($payment->order_id)->toBe($order->id);
        expect($payment->payment_method_id)->toBe($paymentMethod->id);
        expect($payment->amount)->toEqual(12345);
        expect($payment->response_status)->toBe("pending");
        expect($payment->token)->toBe("tok_abc");
        expect($payment->paid_at)->toBeNull();
        expect($payment->generate_random_doc_type)->toBe("invoice");
    },
);

test(
    "recordWebpayResult marks the order completed and the payment authorized",
    function () {
        $order = Order::factory()->create(["status" => "pending"]);
        $paymentMethod = PaymentMethod::where(
            "code",
            "transbank",
        )->firstOrFail();
        $payment = Payment::factory()->create([
            "order_id" => $order->id,
            "payment_method_id" => $paymentMethod->id,
            "response_status" => "pending",
        ]);

        (new PaymentService())->recordWebpayResult($payment, $order, [
            "status" => "AUTHORIZED",
            "authorization_code" => "999",
        ]);

        expect($order->fresh()->status)->toBe("completed");
        $payment->refresh();
        expect($payment->response_status)->toBe("AUTHORIZED");
        expect($payment->auth_code)->toBe("999");
        expect($payment->paid_at)->not->toBeNull();
    },
);

test(
    "recordWebpayResult marks the order failed when transbank result is not authorized",
    function () {
        $order = Order::factory()->create(["status" => "pending"]);
        $paymentMethod = PaymentMethod::where(
            "code",
            "transbank",
        )->firstOrFail();
        $payment = Payment::factory()->create([
            "order_id" => $order->id,
            "payment_method_id" => $paymentMethod->id,
        ]);

        (new PaymentService())->recordWebpayResult($payment, $order, [
            "status" => "FAILED",
        ]);

        expect($order->fresh()->status)->toBe("failed");
        $payment->refresh();
        expect($payment->response_status)->toBe("FAILED");
        expect($payment->auth_code)->toBeNull();
        expect($payment->paid_at)->toBeNull();
    },
);

test("recordAbortedWebpayPayment marks the payment as failed", function () {
    $paymentMethod = PaymentMethod::where("code", "transbank")->firstOrFail();
    $payment = Payment::factory()->create([
        "payment_method_id" => $paymentMethod->id,
        "response_status" => "pending",
    ]);

    (new PaymentService())->recordAbortedWebpayPayment($payment);

    $payment->refresh();
    expect($payment->response_status)->toBe("failed");
    expect($payment->response_message)->toBe(
        json_encode(["message" => "Pago abortado por el usuario"]),
    );
});

test(
    "recordAuthorizedCreditPayment completes the order and records the payment",
    function () {
        $order = Order::factory()->create([
            "status" => "pending",
            "amount" => 5000,
        ]);
        $paymentMethod = PaymentMethod::where(
            "code",
            "random_credit",
        )->firstOrFail();

        $payment = (new PaymentService())->recordAuthorizedCreditPayment(
            $order,
            $paymentMethod,
            "receipt",
            "0000000123",
        );

        expect($order->fresh()->status)->toBe("completed");
        expect($order->fresh()->random_document_number)->toBe("0000000123");
        expect($payment->payment_method_id)->toBe($paymentMethod->id);
        expect($payment->status)->toBe("processing");
        expect($payment->response_status)->toBe("AUTHORIZED");
        expect($payment->amount)->toEqual(5000);
        expect($payment->generate_random_doc_type)->toBe("receipt");
        expect($payment->paid_at)->not->toBeNull();
    },
);

test(
    "recordFailedCreditPayment fails the order and records the failed payment",
    function () {
        $order = Order::factory()->create([
            "status" => "pending",
            "amount" => 5000,
        ]);
        $paymentMethod = PaymentMethod::where(
            "code",
            "random_credit",
        )->firstOrFail();

        $payment = (new PaymentService())->recordFailedCreditPayment(
            $order,
            $paymentMethod,
        );

        expect($order->fresh()->status)->toBe("failed");
        expect($payment->payment_method_id)->toBe($paymentMethod->id);
        expect($payment->status)->toBe("failed");
        expect($payment->response_status)->toBe("FAILED");
        expect($payment->amount)->toEqual(5000);
    },
);

test(
    "recordRandomDocumentNumber attaches the document number to the order",
    function () {
        $order = Order::factory()->create(["random_document_number" => null]);

        (new PaymentService())->recordRandomDocumentNumber(
            $order,
            "0000000456",
        );

        expect($order->fresh()->random_document_number)->toBe("0000000456");
    },
);
