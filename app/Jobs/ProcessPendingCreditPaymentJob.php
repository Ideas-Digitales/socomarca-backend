<?php

namespace App\Jobs;

use App\Exceptions\RandomApiServiceErrorException;
use App\Models\CreditLine;
use App\Models\Payment;
use App\Models\RandomDocument;
use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unblocks a credit line once Random ERP invoices the NVV of the payment that blocked it.
 */
class ProcessPendingCreditPaymentJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Invoice document types that settle the NVV of a credit payment.
     */
    private const INVOICE_TYPES = ['FCV', 'BLV'];

    /**
     * Seconds the unique lock is kept if the job never finishes (e.g. the worker dies),
     * so that the line is checked again in a later cycle.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public CreditLine $creditLine
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->creditLine->id;
    }

    public function handle(RandomApiService $randomApi): void
    {
        if (!$this->creditLine->isBlocked()) {
            return;
        }

        $payment = $this->blockingPayment();

        if (!$payment) {
            Log::warning('Credit line blocked without a processing credit payment, unblocking it', $this->logContext());
            $this->creditLine->unblock();
            return;
        }

        if ($payment->status !== 'processing') {
            Log::warning('Credit payment that blocked the line is no longer processing, unblocking it', $this->logContext($payment, [
                'payment_status' => $payment->status,
            ]));
            $this->creditLine->unblock();
            return;
        }

        $order = $payment->order;
        $nvvDocument = $order?->randomDocuments()->where('type', 'NVV')->first();

        if (!$nvvDocument) {
            Log::warning('Credit payment that blocked the line has no NVV, the line stays blocked', $this->logContext($payment));
            return;
        }

        try {
            $traceResponse = $randomApi->getDocumentTrace($nvvDocument->idmaeedo);
        } catch (RandomApiServiceErrorException|ConnectionException $e) {
            Log::warning('NVV trace query failed, the credit line stays blocked until the next cycle', $this->logContext($payment, [
                'idmaeedo' => $nvvDocument->idmaeedo,
                'message' => $e->getMessage(),
            ]));
            return;
        }

        $invoice = collect($traceResponse->json('data') ?? [])
            ->first(fn ($traceItem) => in_array($traceItem['maeedo']['TIDO'] ?? null, self::INVOICE_TYPES, true));

        if (!$invoice) {
            return;
        }

        $invoiceDocument = RandomDocument::updateOrCreate(
            ['idmaeedo' => $invoice['maeedo']['IDMAEEDO']],
            [
                'type' => $invoice['maeedo']['TIDO'],
                'document' => $invoice['maeedo'],
            ]
        );
        $order->randomDocuments()->syncWithoutDetaching([$invoiceDocument->idmaeedo]);

        try {
            $creditStateResponse = $randomApi->getCreditLine($this->creditLine->user->user_code, $this->creditLine->branch_code);
            $this->creditLine->update(['state' => $creditStateResponse->json()]);
        } catch (\Throwable $e) {
            Log::warning('Error while updating local Random ERP Credit', $this->logContext($payment, [
                'message' => $e->getMessage(),
            ]));
        }

        DB::transaction(function () use ($payment) {
            $this->creditLine->unblock();
            $payment->update(['status' => 'completed']);
        });
    }

    /**
     * Lines blocked before `blocking_payment_id` existed (or whose payment was deleted) fall back
     * to the latest processing credit payment of their user.
     */
    private function blockingPayment(): ?Payment
    {
        if ($this->creditLine->blocking_payment_id !== null) {
            return $this->creditLine->blockingPayment;
        }

        return Payment::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $this->creditLine->user_id))
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'random_credit'))
            ->where('status', 'processing')
            ->latest('id')
            ->first();
    }

    private function logContext(?Payment $payment = null, array $extra = []): array
    {
        return [
            'credit_line_id' => $this->creditLine->id,
            'user_id' => $this->creditLine->user_id,
            'payment_id' => $payment?->id,
            ...$extra,
        ];
    }
}
