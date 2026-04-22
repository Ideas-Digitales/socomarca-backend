<?php

namespace App\Http\Resources;

use App\Http\Resources\Orders\OrderResource;
use App\Http\Resources\PaymentMethods\PaymentMethodResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'auth_code' => $this->auth_code,
            'amount' => $this->amount,
            'response_status' => $this->response_status,
            'token' => $this->token,
            'paid_at' => $this->paid_at,
            'payment_method' => new PaymentMethodResource(
                $this->paymentMethod
            ),
            'order' => new OrderResource($this->whenLoaded('order'))
        ];
    }
}
