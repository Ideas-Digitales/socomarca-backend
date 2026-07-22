<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use App\Services\WebpayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recovers Webpay payments left stuck in "pending" because the browser never
 * made it back to WebpayController::return (lost connection, closed tab,
 * etc.). Polls Transbank's transaction status for each stale pending payment
 * and either finalizes it (same code path as the return callback) or, after
 * enough failed attempts, marks it as failed.
 */
class ReconcileWebpayPendingPaymentsJob implements ShouldQueue
{
    use Queueable;

    private const PENDING_STATUSES = ["pending", "INITIALIZED"];

    public function handle(
        WebpayService $webpayService,
        PaymentService $paymentService,
    ): void {
        // Matches the "code" PaymentMethodSeeder assigns to Transbank; the same
        // row WebpayService::createTransaction() looks up by "name".
        $paymentMethod = PaymentMethod::where("code", "transbank")->first();

        if (!$paymentMethod) {
            return;
        }

        $graceMinutes = (int) config(
            "webpay.reconciliation.grace_period_minutes",
            5,
        );
        $maxAttempts = (int) config(
            "webpay.reconciliation.max_status_check_attempts",
            5,
        );

        $payments = Payment::where("payment_method_id", $paymentMethod->id)
            ->whereIn("response_status", self::PENDING_STATUSES)
            ->where("created_at", "<=", now()->subMinutes($graceMinutes))
            ->where("status_check_attempts", "<", $maxAttempts)
            ->get();

        foreach ($payments as $payment) {
            $this->reconcile($payment, $webpayService, $paymentService, $maxAttempts);
        }
    }

    private function reconcile(
        Payment $payment,
        WebpayService $webpayService,
        PaymentService $paymentService,
        int $maxAttempts,
    ): void {
        DB::transaction(function () use (
            $payment,
            $webpayService,
            $paymentService,
            $maxAttempts,
        ) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            // Resolved concurrently (e.g. the user's return callback landed
            // right before this job picked up the row).
            if (!$locked || !in_array($locked->response_status, self::PENDING_STATUSES, true)) {
                return;
            }

            $order = $locked->order;

            try {
                $status = $webpayService->getTransactionStatus($locked->token);
            } catch (\Exception $e) {
                Log::warning("Webpay reconciliation: status check failed", [
                    "payment_id" => $locked->id,
                    "token" => $locked->token,
                    "error" => $e->getMessage(),
                ]);
                $this->registerFailedAttempt($locked, $order, $maxAttempts);
                return;
            }

            $locked->last_status_checked_at = now();
            $locked->save();

            if ($status["status"] === "INITIALIZED") {
                // Never committed by anyone: attempt the commit ourselves so
                // an authorized-but-unconfirmed transaction still captures.
                try {
                    $result = $webpayService->getTransactionResult($locked->token);
                } catch (\Exception $e) {
                    Log::warning("Webpay reconciliation: late commit failed", [
                        "payment_id" => $locked->id,
                        "token" => $locked->token,
                        "error" => $e->getMessage(),
                    ]);
                    $this->registerFailedAttempt($locked, $order, $maxAttempts);
                    return;
                }

                $paymentService->finalizeWebpayResult($locked, $order, $result);
                return;
            }

            // Transbank already reached a terminal state on its side, but our
            // return callback never landed to record it.
            $paymentService->finalizeWebpayResult($locked, $order, $status);
        });
    }

    private function registerFailedAttempt(
        Payment $payment,
        Order $order,
        int $maxAttempts,
    ): void {
        $payment->increment("status_check_attempts");
        $payment->last_status_checked_at = now();
        $payment->save();

        if ($payment->status_check_attempts < $maxAttempts) {
            return;
        }

        Log::error(
            "Webpay reconciliation: giving up after max status check attempts, marking as failed",
            [
                "payment_id" => $payment->id,
                "order_id" => $order->id,
                "token" => $payment->token,
                "attempts" => $payment->status_check_attempts,
            ],
        );

        $payment->response_status = "FAILED";
        $payment->response_message = json_encode([
            "message" =>
                "No se pudo confirmar la transacción tras múltiples intentos de reconciliación",
        ]);
        $payment->save();

        $order->status = "failed";
        $order->save();
    }
}
