<?php

namespace App\Http\Resources\PaymentMethods;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            /** @var bool */
            'active'        => $this->active,
            /**
             * Code to send as `payment_method` to `POST /orders/pay`.
             *
             * @var string
             * @example random_credit
             */
            'code'          => $this->code,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
