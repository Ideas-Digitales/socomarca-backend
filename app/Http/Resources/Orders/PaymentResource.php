<?php

namespace App\Http\Resources\Orders;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('WebpayTransactionResource')]
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** The created order, pending payment. */
            'order' => new OrderResource($this->order),
            /**
             * Webpay form URL to send the buyer to, posting the `token` as `token_ws`.
             *
             * @var string
             */
            'payment_url' => $this->payment_url,
            /**
             * Token of the Webpay transaction.
             *
             * @var string
             */
            'token' => $this->token
        ];
    }
} 
