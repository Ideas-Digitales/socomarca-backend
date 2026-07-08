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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
