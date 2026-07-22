<?php

return [
    /*
     * Reconciliation of Webpay transactions whose return redirect never
     * reached the backend (lost connection, closed browser, etc.).
     */
    'reconciliation' => [
        // Minimum age a pending payment must reach before the job will
        // query Transbank for its status. Avoids racing the normal
        // return-callback flow, which usually completes within seconds.
        'grace_period_minutes' => (int) env(
            'WEBPAY_RECONCILIATION_GRACE_PERIOD_MINUTES',
            5,
        ),

        // How many times the job will check a stuck payment's status
        // before giving up and marking the order as failed.
        'max_status_check_attempts' => (int) env(
            'WEBPAY_RECONCILIATION_MAX_STATUS_CHECK_ATTEMPTS',
            5,
        ),
    ],
];
