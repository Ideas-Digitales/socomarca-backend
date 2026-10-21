<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Rules\AwaitingInvoiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    /**
     * Missing or unknown order numbers are left to validation. An existing order
     * can be canceled by whoever placed it, by the branch it was placed for, or
     * by a user with both `read-all-orders` and `cancel-orders`.
     */
    public function authorize(): bool
    {
        $orderNumber = $this->input('order_number');

        if (! is_numeric($orderNumber)) {
            return true;
        }

        $order = Order::query()->find((int) $orderNumber);

        if ($order === null) {
            return true;
        }

        return $this->user()?->can('cancel', $order) ?? false;
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
