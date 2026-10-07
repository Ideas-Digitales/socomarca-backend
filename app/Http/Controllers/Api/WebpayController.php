<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderCompleted;
use App\Events\WebpayPaymentAuthorized;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\CartItem;
use App\Services\PaymentService;
use App\Services\WebpayService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Webpay', 'Settle, check and refund the Transbank Webpay Plus transactions started by `POST /orders/pay`.', weight: 13)]
class WebpayController extends Controller
{
    protected WebpayService $webpayService;

    protected PaymentService $paymentService;

    public function __construct(
        WebpayService $webpayService,
        PaymentService $paymentService,
    ) {
        $this->webpayService = $webpayService;
        $this->paymentService = $paymentService;
    }

    /**
     * Settle a Webpay transaction
     *
     * Receives the parameters Transbank sends back with the buyer after the Webpay form, and settles the
     * order:
     *
     * - With `token_ws`, commits the transaction in Transbank and stores the result: the order becomes
     *   `completed` when the payment is `AUTHORIZED` and `failed` otherwise. On authorization the cart of
     *   the user who placed the order is emptied, the order emails are sent and the sales note (NVV) is
     *   created in Random ERP in the background. A token can be committed only once: repeating it, or
     *   sending an unknown token, responds 500.
     * - With `TBK_TOKEN` (the buyer canceled the payment), the payment is marked failed and the response
     *   is 400.
     * - Without either of them (the Webpay form timed out), responds 408.
     */
    #[QueryParameter('token_ws', 'Token of the transaction, sent by Transbank when the payment was completed or rejected.', type: 'string')]
    #[QueryParameter('TBK_TOKEN', 'Token of the transaction, sent by Transbank instead of `token_ws` when the buyer canceled the payment.', type: 'string')]
    #[Response(
        200,
        'Transaction committed; `success` tells whether the payment was authorized',
        type: 'array{success: bool, message: "Pago exitoso"|"Pago fallido", data: array{status: "AUTHORIZED"|"FAILED"|"REVERSED"|"NULLIFIED"|"PARTIALLY_NULLIFIED"|"CAPTURED"|null, amount: int|float|null, authorization_code: string|null, payment_type_code: string|null, response_code: int|null, installments_number: int|null, installments_amount: int|float|null, card_number: string|null, accounting_date: string|null, transaction_date: string|null}}',
    )]
    #[Response(400, 'The buyer canceled the payment')]
    #[Response(408, 'The Webpay form timed out')]
    #[Response(500, 'The transaction could not be committed, or its payment does not exist')]
    public function return(Request $request)
    {
        if ($request->exists("token_ws")) {
            Log::info("Webpay return: Token WS recibido", [
                "token" => $request->token_ws,
            ]);
            try {
                $result = $this->webpayService->getTransactionResult(
                    $request->token_ws,
                );
                $payment = Payment::where("token", $request->token_ws)->first();

                if (!$payment) {
                    Log::error("Webpay return: Pago no encontrado", [
                        "token" => $request->token_ws,
                    ]);
                    throw new \Exception("Pago no encontrado");
                }

                $order = Order::find($payment->order_id);

                if ($order) {
                    Log::info("Webpay return: Orden encontrada", [
                        "order_id" => $order->id,
                        "status" => $result["status"],
                    ]);

                    $this->paymentService->recordWebpayResult(
                        $payment,
                        $order,
                        $result,
                    );

                    Log::info("Webpay return: Orden y pago actualizados", [
                        "order_id" => $order->id,
                        "order_status" => $order->status,
                        "payment_status" => $payment->response_status,
                    ]);

                    if ($order->status === "completed") {
                        CartItem::where("user_id", $order->user_id)->delete();
                        Log::info(
                            "Webpay return: Carrito borrado exitosamente",
                            ["user_id" => $order->user_id],
                        );

                        OrderCompleted::dispatch($order);
                        WebpayPaymentAuthorized::dispatch($order, $payment);
                    }
                } else {
                    Log::warning("Webpay return: Orden no encontrada", [
                        "order_id" => $payment->order_id,
                    ]);
                }

                return response()->json([
                    "success" => $result["status"] === "AUTHORIZED",
                    "message" =>
                        $result["status"] === "AUTHORIZED"
                            ? "Pago exitoso"
                            : "Pago fallido",
                    "data" => $result,
                ]);
            } catch (\Exception $e) {
                Log::error("Webpay return: Error al procesar el pago", [
                    "error" => $e->getMessage(),
                    "token" => $request->token_ws,
                ]);
                return response()->json(
                    [
                        "message" =>
                            "Error al procesar el pago: " . $e->getMessage(),
                    ],
                    500,
                );
            }
        }

        if ($request->exists("TBK_TOKEN")) {
            Log::info("Webpay return: Pago abortado por usuario", [
                "token" => $request->TBK_TOKEN,
            ]);

            $payment = Payment::where("token", $request->TBK_TOKEN)->first();
            if ($payment) {
                $this->paymentService->recordAbortedWebpayPayment($payment);
            }

            return response()->json(
                [
                    "success" => false,
                    "message" => "Pago abortado por el usuario",
                    "token" => $request->TBK_TOKEN,
                ],
                400,
            );
        }

        Log::warning("Webpay return: Tiempo de espera agotado");
        return response()->json(
            [
                "success" => false,
                "message" => "Tiempo de espera agotado",
            ],
            408,
        );
    }

    /**
     * Get the status of a Webpay transaction
     *
     * Queries Transbank for the current status of a transaction, without changing the order or its payment.
     */
    #[QueryParameter('token', 'Token of the transaction, as returned by `POST /orders/pay`.', required: true, type: 'string')]
    #[Response(
        200,
        'Transaction status reported by Transbank',
        type: 'array{success: true, data: array{status: "INITIALIZED"|"AUTHORIZED"|"FAILED"|"REVERSED"|"NULLIFIED"|"PARTIALLY_NULLIFIED"|"CAPTURED"|null, amount: int|float|null, authorization_code: string|null, payment_type_code: string|null, response_code: int|null, installments_number: int|null, installments_amount: int|float|null, card_number: string|null, accounting_date: string|null, transaction_date: string|null}}',
    )]
    #[Response(500, 'Transbank could not report the status, for example because the token is unknown')]
    public function status(Request $request)
    {
        try {
            Log::info("Webpay status: Consultando estado de transacción", [
                "token" => $request->token,
            ]);
            $result = $this->webpayService->getTransactionStatus(
                $request->token,
            );
            return response()->json([
                "success" => true,
                "data" => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Webpay status: Error al obtener estado", [
                "error" => $e->getMessage(),
                "token" => $request->token,
            ]);
            return response()->json(
                [
                    "success" => false,
                    "message" =>
                        "Error al obtener el estado: " . $e->getMessage(),
                ],
                500,
            );
        }
    }

    /**
     * Refund a Webpay transaction
     *
     * Refunds all or part of an authorized transaction in Transbank and marks its payment as `refunded`.
     * The order status is not changed.
     */
    #[BodyParameter('token', 'Token of the transaction, as returned by `POST /orders/pay`.', required: true, type: 'string')]
    #[BodyParameter('amount', 'Amount to refund.', required: true, type: 'int', example: 10000)]
    #[Response(
        200,
        'Refund processed by Transbank',
        type: 'array{success: true, data: array{type: "REVERSED"|"NULLIFIED"|null, balance: int|float|null, response_code: int|null, authorization_code: string|null, authorization_date: string|null}}',
    )]
    #[Response(500, 'Transbank rejected the refund, or the payment of the token does not exist')]
    public function refund(Request $request)
    {
        try {
            Log::info("Webpay refund: Iniciando reembolso", [
                "token" => $request->token,
                "amount" => $request->amount,
            ]);
            $result = $this->webpayService->refundTransaction(
                $request->token,
                $request->amount,
            );

            $payment = Payment::where("token", $request->token)->first();
            $payment->response_status = "refunded";
            $payment->response_message = json_encode($result);
            $payment->save();

            return response()->json([
                "success" => true,
                "data" => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Webpay refund: Error al procesar reembolso", [
                "error" => $e->getMessage(),
                "token" => $request->token,
                "amount" => $request->amount,
            ]);
            return response()->json(
                [
                    "success" => false,
                    "message" =>
                        "Error al procesar el reembolso: " . $e->getMessage(),
                ],
                500,
            );
        }
    }
}
