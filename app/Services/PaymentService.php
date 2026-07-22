<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Events\WebpayPaymentAuthorized;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;

class PaymentService
{
    /**
     * Create the pending Payment row for a newly created Webpay transaction.
     */
    public function createPendingWebpayPayment(
        Order $order,
        PaymentMethod $paymentMethod,
        string $token,
        string $generateRandomDocType,
    ): Payment {
        return Payment::create([
            "order_id" => $order->id,
            "payment_method_id" => $paymentMethod->id,
            "auth_code" => "1",
            "amount" => $order->amount,
            "response_status" => "pending",
            "response_message" => json_encode([]),
            "token" => $token,
            "paid_at" => null,
            "generate_random_doc_type" => $generateRandomDocType,
        ]);
    }

    /**
     * Persist the Transbank result on the order and its payment.
     */
    public function recordWebpayResult(
        Payment $payment,
        Order $order,
        array $transbankResult,
    ): void {
        $order->status =
            $transbankResult["status"] === "AUTHORIZED"
                ? "completed"
                : "failed";
        $order->save();

        $payment->response_status = $transbankResult["status"];
        $payment->response_message = json_encode($transbankResult);
        $payment->auth_code = $transbankResult["authorization_code"] ?? null;
        $payment->paid_at =
            $transbankResult["status"] === "AUTHORIZED" ? now() : null;
        $payment->save();
    }

    /**
     * Apply a Transbank result to the order/payment and, when it completes the
     * order, run the same side effects the return callback triggers (clear the
     * cart, dispatch OrderCompleted/WebpayPaymentAuthorized). Shared by
     * WebpayController::return and the pending-payment reconciliation job so
     * both entry points behave identically.
     */
    public function finalizeWebpayResult(
        Payment $payment,
        Order $order,
        array $transbankResult,
    ): void {
        $this->recordWebpayResult($payment, $order, $transbankResult);

        if ($order->status === "completed") {
            CartItem::where("user_id", $order->user_id)->delete();
            OrderCompleted::dispatch($order);
            WebpayPaymentAuthorized::dispatch($order, $payment);
        }
    }

    /**
     * Mark a Webpay payment as aborted by the user before completing the transaction.
     */
    public function recordAbortedWebpayPayment(Payment $payment): void
    {
        $payment->response_status = "failed";
        $payment->response_message = json_encode([
            "message" => "Pago abortado por el usuario",
        ]);
        $payment->save();
    }

    /**
     * Mark a credit-line order as completed and record its authorized payment,
     * once the Random ERP document has been created successfully.
     */
    public function recordAuthorizedCreditPayment(
        Order $order,
        PaymentMethod $paymentMethod,
        string $generateRandomDocType,
        string $randomDocumentNumber,
    ): Payment {
        $order->update([
            "status" => "completed",
            "random_document_number" => $randomDocumentNumber,
        ]);

        return $order->payments()->create([
            "payment_method_id" => $paymentMethod->id,
            "status" => "processing",
            "response_status" => "AUTHORIZED",
            "auth_code" => uniqid(),
            "amount" => $order->amount,
            "response_message" => ["message" => "Aprobado"],
            "token" => uniqid(),
            "paid_at" => now(),
            "generate_random_doc_type" => $generateRandomDocType,
        ]);
    }

    /**
     * Mark a credit-line order as failed and record the failed payment,
     * because the Random ERP document could not be created.
     */
    public function recordFailedCreditPayment(
        Order $order,
        PaymentMethod $paymentMethod,
    ): Payment {
        $order->update(["status" => "failed"]);

        return $order->payments()->create([
            "payment_method_id" => $paymentMethod->id,
            "status" => "failed",
            "response_status" => "FAILED",
            "response_message" => [
                "message" => "Error al crear NVV en Random ERP",
            ],
            "token" => uniqid(),
            "amount" => $order->amount,
        ]);
    }

    /**
     * Attach the Random ERP document number to an already-completed order.
     */
    public function recordRandomDocumentNumber(
        Order $order,
        string $randomDocumentNumber,
    ): void {
        $order->random_document_number = $randomDocumentNumber;
        $order->save();
    }
}
