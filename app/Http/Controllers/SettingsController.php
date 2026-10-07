<?php

namespace App\Http\Controllers;

use App\Models\Siteinfo;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Settings', 'Store-wide settings: price quantity limits, VAT rate, maximum upload size and the Webpay configuration.', weight: 20)]
class SettingsController extends Controller
{
    /**
     * Get the price settings
     */
    public function index()
    {
        $settings = Siteinfo::where('key', 'prices_settings')->first();
        return response()->json([
            /**
             * Whether minimum and maximum purchase quantities are enabled. The API only stores this flag
             * for clients; it does not enforce any quantity limit.
             *
             * @var bool
             */
            'min_max_quantity_enabled' => $settings ? ($settings->value['min_max_quantity_enabled'] ?? false) : false,
        ]);
    }

    /**
     * Update the price settings
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            /** Whether minimum and maximum purchase quantities are enabled. */
            'min_max_quantity_enabled' => 'required|boolean',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => 'prices_settings'],
            ['value' => $data]
        );

        return response()->json(['message' => 'Configuración actualizada correctamente']);
    }




}
