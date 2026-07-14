<?php

namespace App\Listeners;

use App\Enums\PaymentDocumentType;
use App\Events\WebpayPaymentAuthorized;
use App\Services\PaymentService;
use App\Services\Random\RandomDocumentPayloadBuilder;
use App\Services\Random\RandomDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateWebpayRandomDocument implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private RandomDocumentPayloadBuilder $payloadBuilder,
        private RandomDocumentService $documentService,
        private PaymentService $paymentService,
    ) {}

    /**
     * Handle the event.
     *
     * Best-effort side effect: money already moved via Transbank by the time this
     * runs, so a failure here is logged only and never reverts the order/payment.
     */
    public function handle(WebpayPaymentAuthorized $event): void
    {
        $order = $event->order;
        $payment = $event->payment;

        if ($order->randomDocuments()->exists()) {
            return;
        }

        $docType =
            $payment->generate_random_doc_type ?? PaymentDocumentType::RECEIPT;
        $payload = $this->payloadBuilder->build(
            $order,
            $docType,
            "Pago por Webpay",
        );

        try {
            $documentResponse = $this->documentService->createDocument(
                $payload,
                $order,
            );

            if (isset($documentResponse["errorId"])) {
                Log::error(
                    "CreateWebpayRandomDocument: Random ERP business error creating NVV document",
                    [
                        "order_id" => $order->id,
                        "payment_id" => $payment->id,
                        "response" => $documentResponse,
                    ],
                );
                return;
            }

            $this->paymentService->recordRandomDocumentNumber(
                $order,
                $documentResponse["numero"],
            );
        } catch (\Throwable $th) {
            Log::error(
                "Error al crear documento NVV Random para pago por Webpay",
                [
                    "order_id" => $order->id,
                    "payment_id" => $payment->id,
                    "exception" => $th,
                ],
            );
        }
    }
}
