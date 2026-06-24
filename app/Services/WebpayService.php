<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Siteinfo;
use Transbank\Webpay\WebpayPlus;
use Transbank\Webpay\WebpayPlus\Transaction;
use Transbank\Webpay\Options;
use Illuminate\Support\Facades\Log;

class WebpayService
{
    protected $transaction;
    protected $webpayData;

    public function __construct()
    {
        Log::info('WebpayService: Inicializando servicio', ['environment' => app()->environment()]);
        $siteinfo = Siteinfo::where('key', 'WEBPAY_INFO')->first();
        $this->webpayData = $siteinfo ? $siteinfo->value : [];

        if(!$this->webpayData){
            return response()->json([
                'message' => 'No se encontró la configuración de Webpay',
                'data' => []
            ],404);
        }

        if ($this->webpayData['WEBPAY_ENVIRONMENT'] === 'production') {
            $options = new Options(
                $this->webpayData['WEBPAY_API_KEY'],
                $this->webpayData['WEBPAY_COMMERCE_CODE'],
                Options::ENVIRONMENT_PRODUCTION
            );
            Log::info('WebpayService: Configurando ambiente de producción');
        } else {
            $options = new Options(
                $this->webpayData['WEBPAY_API_KEY'],
                $this->webpayData['WEBPAY_COMMERCE_CODE'],
                Options::ENVIRONMENT_INTEGRATION
            );
            Log::info('WebpayService: Configurando ambiente de integración');
        }

        $this->transaction = new Transaction($options);
    }

    /**
     * Crea una nueva transacción de Webpay
     *
     * @param Order $order
     * @param string $generateRandomDocType Random Document type to generate in ERP
     * @return array
     */
    public function createTransaction(Order $order, string $generateRandomDocType)
    {
        Log::info('WebpayService: Creando nueva transacción', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => $order->amount
        ]);

        try {
            $response = $this->transaction->create(
                $order->id,
                $order->user_id,
                $order->amount,
                $this->webpayData['WEBPAY_RETURN_URL']
            );


            Log::info('WebpayService: Transacción creada exitosamente', [
                'order_id' => $order->id,
                'token' => $response->getToken()
            ]);

            Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => PaymentMethod::where('name', 'Transbank')->first()->id,
                'auth_code' => '1',
                'amount' => $order->amount,
                'response_status' => 'pending',
                'response_message' => json_encode([]),
                'token' => $response->getToken(),
                'paid_at' => null,
                'generate_random_doc_type' => $generateRandomDocType,
            ]);

            return [
                'url' => $response->getUrl(),
                'token' => $response->getToken()
            ];

        } catch (\Exception $e) {
            Log::error('WebpayService: Error al crear transacción', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verifica el resultado de una transacción
     *
     * @param string $token
     * @return array
     */
    public function getTransactionResult(string $token)
    {
        Log::info('WebpayService: Verificando resultado de transacción', ['token' => $token]);

        try {
            $result = $this->transaction->commit($token);

            $response = [
                'status' => $result->getStatus(),
                'amount' => $result->getAmount(),
                'authorization_code' => $result->getAuthorizationCode(),
                'payment_type_code' => $result->getPaymentTypeCode(),
                'response_code' => $result->getResponseCode(),
                'installments_number' => $result->getInstallmentsNumber(),
                'installments_amount' => $result->getInstallmentsAmount(),
                'card_number' => $result->getCardNumber(),
                'accounting_date' => $result->getAccountingDate(),
                'transaction_date' => $result->getTransactionDate(),
            ];



            Log::info('WebpayService: Resultado de transacción obtenido', [
                'token' => $token,
                'status' => $response['status'],
                'response_code' => $response['response_code']
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('WebpayService: Error al obtener resultado de transacción', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene el estado de una transacción
     *
     * @param string $token
     * @return array
     */
    public function getTransactionStatus(string $token)
    {
        Log::info('WebpayService: Consultando estado de transacción', ['token' => $token]);

        try {
            $result = $this->transaction->status($token);

            $response = [
                'status' => $result->getStatus(),
                'amount' => $result->getAmount(),
                'authorization_code' => $result->getAuthorizationCode(),
                'payment_type_code' => $result->getPaymentTypeCode(),
                'response_code' => $result->getResponseCode(),
                'installments_number' => $result->getInstallmentsNumber(),
                'installments_amount' => $result->getInstallmentsAmount(),
                'card_number' => $result->getCardNumber(),
                'accounting_date' => $result->getAccountingDate(),
                'transaction_date' => $result->getTransactionDate()
            ];

            Log::info('WebpayService: Estado de transacción obtenido', [
                'token' => $token,
                'status' => $response['status'],
                'response_code' => $response['response_code']
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('WebpayService: Error al obtener estado de transacción', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Realiza un reembolso de una transacción
     *
     * @param string $token
     * @param float $amount
     * @return array
     */
    public function refundTransaction(string $token, float $amount)
    {
        Log::info('WebpayService: Iniciando reembolso de transacción', [
            'token' => $token,
            'amount' => $amount
        ]);

        try {
            $result = $this->transaction->refund($token, $amount);

            $response = [
                'type' => $result->getType(),
                'balance' => $result->getBalance(),
                'response_code' => $result->getResponseCode(),
                'authorization_code' => $result->getAuthorizationCode(),
                'authorization_date' => $result->getAuthorizationDate()
            ];

            Log::info('WebpayService: Reembolso procesado exitosamente', [
                'token' => $token,
                'amount' => $amount,
                'response_code' => $response['response_code']
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('WebpayService: Error al procesar reembolso', [
                'token' => $token,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
