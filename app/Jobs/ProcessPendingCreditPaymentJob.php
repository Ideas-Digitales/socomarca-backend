<?php

namespace App\Jobs;

use App\Models\CreditLine;
use App\Models\Payment;
use App\Models\RandomDocument;
use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessPendingCreditPaymentJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CreditLine $creditLine
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RandomApiService $randomApi): void
    {
        // 1. Consultar si el crédito está bloqueado. Si no lo está, la tarea termina.
        if (!$this->creditLine->isBlocked()) {
            return;
        }

        // 2. Consultar los pagos a crédito del usuario en status "processing"
        $payments = Payment::with(['order', 'paymentMethod'])
            ->whereHas('order', function ($query) {
                // Filtramos por el usuario dueño de la línea de crédito
                $query->where('user_id', $this->creditLine->user_id);
            })
            ->whereHas('paymentMethod', function ($query) {
                $query->where('code', 'random_credit');
            })
            ->where('status', 'processing')
            ->get();

        if ($payments->isEmpty()) {
            return;
        }

        // 3. Si hay más de dos pagos en procesamiento, emitir un log de error
        if ($payments->count() > 2) {
            Log::error('Usuario con múltiples pagos a crédito en proceso', [
                'user_id' => $this->creditLine->user_id,
                'processing_payments_count' => $payments->count()
            ]);
        }

        // 4. Tomar el primer pago y consultar su documento
        $payment = $payments->first();
        $order = $payment->order;

        if (!$order) {
            return;
        }

        $nvvDocument = $order->randomDocuments()->where('type', 'NVV')->first();

        if (!$nvvDocument) {
            return;
        }

        // 5. Consultar al API la traza del documento de nota de venta
        $traceResponse = $randomApi->getDocumentTrace($nvvDocument->idmaeedo);

        if (!$traceResponse->successful() || empty($traceResponse->json('data'))) {
            return;
        }

        // 6. Evaluar si en la respuesta hay algún documento de factura de venta "FCV"
        $fcvDocumentPayload = null;

        foreach ($traceResponse->json('data') as $traceItem) {
            if (isset($traceItem['maeedo']['TIDO']) && $traceItem['maeedo']['TIDO'] === 'FCV') {
                $fcvDocumentPayload = $traceItem;
                break;
            }
        }

        // 7. Si hay un FCV, guardar en sistema y desbloquear crédito
        if ($fcvDocumentPayload) {
            $fcvId = $fcvDocumentPayload['maeedo']['IDMAEEDO'];

            $fcvRecord = RandomDocument::updateOrCreate(
                ['idmaeedo' => $fcvId],
                [
                    'type'     => 'FCV',
                    'document' => $fcvDocumentPayload['maeedo'] // o el payload completo, según prefieras
                ]
            );

            // Verificamos si la orden ya tiene atachado el documento FCV, sino lo atachamos
            if (!$order->randomDocuments()->where('random_documents.idmaeedo', $fcvId)->exists()) {
                $order->randomDocuments()->attach($fcvRecord->idmaeedo);
            }

            try {
                $creditStateResponse = $randomApi->getCreditLine($this->creditLine->user->rut, $this->creditLine->branch_code);
                $this->creditLine->update([
                    'state' => $creditStateResponse->json(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Error while updating local Random ERP Credit', [
                    'message' => $e->getMessage()
                ]);
            } finally {
                // Desbloqueamos el crédito
                $this->creditLine->unblock();

                // Cambiamos el estado del Pago a 'completed'
                $payment->update(['status' => 'completed']);
            }
        }
    }
}
