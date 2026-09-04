<?php

namespace App\Services;

use App\Models\Siteinfo;

/**
 * Resolution and application of VAT.
 *
 * The rate is configurable from siteinfo (key "vat_settings", manageable in
 * /settings/vat) and falls back to config('vat.rate') if the key has not yet been set.
 *
 * The rate is stored as a percentage (19 = 19%), not as a multiplier, so that
 * it can be displayed as is in the purchase order and in the detail of each line.
 */
class VatService
{
    /**
     * Registry key of siteinfo that stores the VAT configuration.
     */
    public const SETTINGS_KEY = 'vat_settings';

    /**
     * Current VAT rate, in percentage.
     *
     * Check siteinfo in each call: anyone going through many rows (for example, the
     * list of products) should resolve it only once and reuse the value.
     */
    public function rate(): float
    {
        $settings = Siteinfo::where('key', self::SETTINGS_KEY)->first();
        $rate = $settings?->value['rate'] ?? null;

        if (! is_numeric($rate)) {
            return (float) config('vat.rate');
        }

        return (float) $rate;
    }

    /**
     * Multiplier equivalent to the rate (19% => 1.19).
     */
    public function multiplier(?float $rate = null): float
    {
        return 1 + ($rate ?? $this->rate()) / 100;
    }

    /**
     * VAT amount corresponding to a net value.
     *
     * @param float $net Net value (without VAT)
     * @param float|null $rate Rate to apply; by default the current one
     * @param int $decimals Decimals for rounding (0 for amounts in pesos)
     */
    public function amountFor(float $net, ?float $rate = null, int $decimals = 2): float
    {
        return round($net * (($rate ?? $this->rate()) / 100), $decimals);
    }

    /**
     * Gross value (net + VAT) of a net value.
     *
     * @param float $net Net value (without VAT)
     * @param float|null $rate Rate to apply; default is the current one
     * @param int $decimals Rounding decimals (0 for amounts in pesos)
     */
    public function applyTo(float $net, ?float $rate = null, int $decimals = 2): float
    {
        return round($net * $this->multiplier($rate), $decimals);
    }

    /**
     * Net value contained in a gross value. Inverse of applyTo().
     *
     * It is used to translate to net the price filters that the customer sends
     * with VAT included, since in the database prices are stored net.
     *
     * @param float $gross Value with VAT included
     * @param float|null $rate Rate to apply; by default the current one
     * @param int $decimals Rounding decimals
     */
    public function netFrom(float $gross, ?float $rate = null, int $decimals = 2): float
    {
        return round($gross / $this->multiplier($rate), $decimals);
    }
}
