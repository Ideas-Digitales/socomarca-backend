<?php

namespace App\Http\Resources;

use App\Http\Resources\Orders\OrderResource;
use App\Http\Resources\PaymentMethods\PaymentMethodResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('PaymentResource')]
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Authorization code of the payment: the Transbank one for Webpay payments. */
            'auth_code' => $this->auth_code,
            /**
             * Amount of the payment, as a decimal string.
             *
             * @var string
             */
            'amount' => $this->amount,
            /**
             * Payment status: `pending` while the Webpay transaction is open, the Transbank status once
             * committed (`AUTHORIZED`, `FAILED`...), `failed` when the buyer canceled the Webpay payment,
             * `refunded` after a Webpay refund, and `AUTHORIZED` or `FAILED` for credit payments.
             *
             * @var string
             */
            'response_status' => $this->response_status,
            /** Webpay transaction token; a generated identifier for credit payments. */
            'token' => $this->token,
            /**
             * When the payment was authorized.
             *
             * @var string|null
             * @format date-time
             */
            'paid_at' => $this->paid_at,
            'payment_method' => new PaymentMethodResource(
                $this->paymentMethod
            ),
            /** The order, when it is loaded (as in the credit payment response). */
            'order' => new OrderResource($this->whenLoaded('order'))
        ];
    }
}
