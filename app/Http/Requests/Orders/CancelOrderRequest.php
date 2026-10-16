<?php

namespace App\Http\Requests\Orders;

use App\Rules\AwaitingInvoiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Purchase order number (`orders.id`).
             *
             * @example 1234
             */
            'order_number' => [
                'bail',
                'required',
                'integer',
                'exists:orders,id',
                new AwaitingInvoiceOrder,
            ],
        ];
    }
}
