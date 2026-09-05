<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default VAT rate
    |--------------------------------------------------------------------------
    |
    | VAT percentage applied when the key "vat_settings" does not exist in
    | siteinfo (the source of truth, manageable from /settings/vat).
    |
    */
    'rate' => (float) env('VAT_RATE', 19),
];
