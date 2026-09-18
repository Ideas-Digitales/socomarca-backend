<?php

namespace App\Services;

use App\Models\Siteinfo;

/**
 * Resolution of the category association rules.
 *
 * Random ERP delivers each product with two independent category codes, FMPR
 * (superfamily, level 1) and PFPR (family, level 2), and SyncRandomProducts resolves
 * each one on its own, by code + level. Nothing forces the family found to hang from
 * the superfamily found, and the Random data does contain incoherent pairs — product
 * 10956 arrives with FMPR "0003" and PFPR "0003", where the level-2 "0003" belongs to
 * the level-1 "0001".
 *
 * The strictness is configurable from siteinfo (key "category_association_settings",
 * manageable in /settings/category-association) and falls back to
 * config('category_association.strict_category_association_enabled') when the key has
 * not been set yet.
 *
 * @see \App\Jobs\SyncRandomProducts
 * @see \App\Models\Product::scopeConsistentlyFiled()
 */
class CategoryAssociationService
{
    /**
     * Registry key of siteinfo that stores the category association configuration.
     */
    public const SETTINGS_KEY = 'category_association_settings';

    /**
     * Name of the flag inside the siteinfo value, shared with the config fallback.
     */
    public const FLAG = 'strict_category_association_enabled';

    /**
     * Whether products with a contradictory category chain must be kept out of every
     * category-derived output (the category tree and the search facets).
     *
     * Reads siteinfo on every call, the same way VatService does: whoever runs over
     * many rows should resolve it once and reuse the value.
     */
    public function strictEnabled(): bool
    {
        $settings = Siteinfo::where('key', self::SETTINGS_KEY)->first();
        $enabled = $settings?->value[self::FLAG] ?? null;

        if ($enabled === null) {
            return (bool) config('category_association.' . self::FLAG);
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }
}
