<?php

namespace App\Services\Orders;

use App\Enums\PaymentDocumentType;
use App\Enums\PaymentStatus;
use App\Exceptions\RandomApiServiceErrorException;
use App\Exceptions\RandomDocumentNotDeletableException;
use App\Models\CreditLine;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Random\DocumentTrace;
use App\Services\RandomApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an order that is still waiting for Random ERP to issue a tax document
 * (invoice FCV or receipt BLV).
 */
class CancelAwaitingInvoiceOrder
{
    /**
     * Document type of a dispatch guide in the NVV trace.
     */
    private const DISPATCH_TYPE = 'GDV';

    /**
     * Invoice document type (factura).
     */
    private const INVOICE_TYPE = 'FCV';

    /**
     * Receipt document type (boleta).
     */
    private const RECEIPT_TYPE = 'BLV';

    public function __construct(private RandomApiService $randomApi) {}

    /**
     * Reason the order cannot be canceled, as a key of `validation.custom.order_number`.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function rejection(Order $order): ?string
    {
        $order->loadMissing(['payments.paymentMethod', 'randomDocuments']);

        return $this->localRejection($order) ?? $this->traceRejection($order);
    }

    /**
     * Delete the NVV in Random ERP and mark the order and its credit payment as canceled.
     *
     * @param Order $order
     *
     * @return Order
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

        try {
            $this->randomApi->deleteDocument((int) $nvv->idmaeedo);
        } catch (RandomDocumentNotDeletableException $e) {
            $reason = $this->associatedDocumentRejection(collect($e->associated)) ?? 'not_awaiting_invoice';

            throw ValidationException::withMessages([
                'order_number' => [__('validation.custom.order_number.'.$reason)],
            ]);
        }

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

    /**
     * Local reason the order cannot be canceled, before asking Random ERP for the NVV trace.
     *
     * @param Order $order
     *
     * @return string|null
     */
    private function localRejection(Order $order): ?string
    {
        if ($order->status === 'canceled') {
            return 'already_canceled';
        }

        if ($reason = $this->issuedDocumentRejection($order->randomDocuments->pluck('type'))) {
            return $reason;
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
     * Credit payment still waiting for Random ERP to turn its NVV into an invoice (FCV) or a receipt (BLV).
     *
     * @param Order $order
     *
     * @return Payment|null
     */
    private function awaitingInvoicePayment(Order $order): ?Payment
    {
        return $order->payments->first(function (Payment $payment) {
            return $payment->status === PaymentStatus::PROCESSING
                && in_array($payment->generate_random_doc_type, [
                    PaymentDocumentType::INVOICE,
                    PaymentDocumentType::RECEIPT,
                ], true)
                && $payment->paymentMethod?->code === 'random_credit';
        });
    }

    /**
     * Rejection key when an invoice (FCV) or a receipt (BLV) was already issued.
     *
     * @param iterable<int, string|null> $types Document types attached to the order or present in the NVV trace
     *
     * @return string|null
     */
    private function issuedDocumentRejection(iterable $types): ?string
    {
        $types = collect($types);

        if ($types->contains(self::INVOICE_TYPE)) {
            return 'invoiced';
        }

        if ($types->contains(self::RECEIPT_TYPE)) {
            return 'receipt_issued';
        }

        return null;
    }

    /**
     * A non-credit payment already authorized, or any payment marked completed.
     *
     * Credit payments set `paid_at` when the NVV is created and stay `processing`
     * until the invoice or the receipt exists, so that timestamp does not mean they were charged.
     *
     * @param Order $order
     *
     * @return bool
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

    /**
     * Reason from the Random ERP NVV trace when the order is no longer waiting for a tax document.
     *
     * @param Order $order
     *
     * @return string|null
     */
    private function traceRejection(Order $order): ?string
    {
        $nvv = $order->randomDocuments->firstWhere('type', 'NVV');

        try {
            $response = $this->randomApi->getDocumentTrace((int) $nvv->idmaeedo);
        } catch (RandomApiServiceErrorException|ConnectionException) {
            return 'unverified';
        }

        return $this->associatedDocumentRejection(
            DocumentTrace::fromResponse($response)->associatedWith((int) $nvv->idmaeedo)
        );
    }

    /**
     * Rejection key for documents linked to the NVV in its Random ERP trace.
     *
     * @param Collection<int, array{type: string}> $associated
     *
     * @return string|null
     */
    private function associatedDocumentRejection(Collection $associated): ?string
    {
        if ($associated->isEmpty()) {
            return null;
        }

        $types = $associated->pluck('type');

        if ($reason = $this->issuedDocumentRejection($types)) {
            return $reason;
        }

        if ($types->contains(self::DISPATCH_TYPE)) {
            return 'dispatched';
        }

        return 'not_awaiting_invoice';
    }
}
