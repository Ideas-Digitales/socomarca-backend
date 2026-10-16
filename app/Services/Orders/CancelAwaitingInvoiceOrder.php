<?php

namespace App\Services\Orders;

use App\Enums\PaymentDocumentType;
use App\Enums\PaymentStatus;
use App\Exceptions\RandomApiServiceErrorException;
use App\Models\CreditLine;
use App\Models\Order;
use App\Models\Payment;
use App\Services\RandomApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an order that is still waiting for Random ERP to issue an FCV invoice.
 */
class CancelAwaitingInvoiceOrder
{
    /**
     * Document type of a dispatch guide in the NVV trace.
     */
    private const DISPATCH_TYPE = 'GDV';

    /**
     * Invoice document type this cancellation is limited to.
     */
    private const INVOICE_TYPE = 'FCV';

    public function __construct(private RandomApiService $randomApi) {}

    /**
     * Reason the order cannot be canceled, as a key of `validation.custom.order_number`.
     */
    public function rejection(Order $order): ?string
    {
        $order->loadMissing(['payments.paymentMethod', 'randomDocuments']);

        return $this->localRejection($order) ?? $this->traceRejection($order);
    }

    /**
     * Delete the NVV in Random ERP and mark the order and its credit payment as canceled.
     */
    public function cancel(Order $order): Order
    {
        $order->load(['payments.paymentMethod', 'randomDocuments']);

        if ($reason = $this->rejection($order)) {
            throw ValidationException::withMessages([
                'order_number' => [__('validation.custom.order_number.'.$reason)],
            ]);
        }

        $nvv = $order->randomDocuments->firstWhere('type', 'NVV');
        $this->randomApi->deleteDocument((int) $nvv->idmaeedo);

        DB::transaction(function () use ($order) {
            $payment = $this->awaitingInvoicePayment($order);
            $payment->update(['status' => PaymentStatus::CANCELED]);
            $order->update(['status' => 'canceled']);

            CreditLine::query()
                ->where('blocking_payment_id', $payment->id)
                ->get()
                ->each(fn (CreditLine $line) => $line->unblock());
        });

        return $order->refresh();
    }

    private function localRejection(Order $order): ?string
    {
        if ($order->status === 'canceled') {
            return 'already_canceled';
        }

        if ($order->randomDocuments->contains('type', self::INVOICE_TYPE)) {
            return 'invoiced';
        }

        if ($order->randomDocuments->contains('type', self::DISPATCH_TYPE)) {
            return 'dispatched';
        }

        if ($order->payments->contains('status', PaymentStatus::COMPLETED)) {
            return 'charged';
        }

        if ($this->awaitingInvoicePayment($order) === null) {
            return $this->isCharged($order) ? 'charged' : 'not_awaiting_invoice';
        }

        if (! $order->randomDocuments->contains('type', 'NVV')) {
            return 'not_awaiting_invoice';
        }

        return null;
    }

    /**
     * Credit payment still waiting for Random ERP to turn its NVV into an FCV.
     */
    private function awaitingInvoicePayment(Order $order): ?Payment
    {
        return $order->payments->first(function (Payment $payment) {
            return $payment->status === PaymentStatus::PROCESSING
                && $payment->generate_random_doc_type === PaymentDocumentType::INVOICE
                && $payment->paymentMethod?->code === 'random_credit';
        });
    }

    /**
     * A non-credit payment already authorized, or any payment marked completed.
     * Credit payments set `paid_at` when the NVV is created and stay `processing`
     * until the invoice exists, so that timestamp does not mean they were charged.
     */
    private function isCharged(Order $order): bool
    {
        return $order->payments->contains(function (Payment $payment) {
            if ($payment->status === PaymentStatus::COMPLETED) {
                return true;
            }

            if ($payment->paymentMethod?->code === 'random_credit') {
                return false;
            }

            return $payment->paid_at !== null || $payment->response_status === 'AUTHORIZED';
        });
    }

    private function traceRejection(Order $order): ?string
    {
        $nvv = $order->randomDocuments->firstWhere('type', 'NVV');

        try {
            $response = $this->randomApi->getDocumentTrace((int) $nvv->idmaeedo);
        } catch (RandomApiServiceErrorException|ConnectionException) {
            return 'unverified';
        }

        $types = collect($response->json('data') ?? [])
            ->map(fn ($item) => $item['maeedo']['TIDO'] ?? null)
            ->filter()
            ->unique();

        if ($types->contains(self::INVOICE_TYPE)) {
            return 'invoiced';
        }

        if ($types->contains(self::DISPATCH_TYPE)) {
            return 'dispatched';
        }

        if ($types->contains(fn (string $type) => $type !== 'NVV')) {
            return 'not_awaiting_invoice';
        }

        return null;
    }
}
