<?php

namespace App\Rules;

use App\Models\Order;
use App\Services\Orders\CancelAwaitingInvoiceOrder;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The order is waiting for Random ERP to issue an invoice (FCV) or a receipt (BLV).
 */
class AwaitingInvoiceOrder implements ValidationRule
{
    /**
     * Reject the order when it is not waiting for an invoice (FCV) or a receipt (BLV).
     *
     * @param string $attribute
     * @param mixed $value
     * @param Closure $fail
     *
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $order = Order::query()->find($value);

        if (! $order) {
            return;
        }

        $reason = app(CancelAwaitingInvoiceOrder::class)->rejection($order);

        if ($reason !== null) {
            $fail('validation.custom.order_number.'.$reason)->translate();
        }
    }
}
