<?php

namespace App\Enums;

class PaymentStatus
{
    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /**
     * The credit payment was canceled before Random ERP issued the invoice or the receipt.
     * `orders.status` already uses `canceled`; this value did not exist on payments.
     */
    public const CANCELED = 'canceled';

    /**
     * Payment status values stored in `payments.status`.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::CANCELED,
        ];
    }
}
