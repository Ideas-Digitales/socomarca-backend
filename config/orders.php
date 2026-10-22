<?php

return [
    /*
     * Minutes after the credit payment is authorized during which the buyer
     * can cancel the order. Users with both `read-all-orders` and `cancel-orders`
     * are not limited by this window.
     */
    'cancellation_window_minutes' => max(0, (int) env('ORDER_CANCELLATION_WINDOW_MINUTES', 60)),
];
