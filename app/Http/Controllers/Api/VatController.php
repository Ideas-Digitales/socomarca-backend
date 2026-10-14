<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Siteinfo;
use App\Services\VatService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Settings')]
class VatController extends Controller
{
    /**
     * Get the VAT rate
     *
     * Returns the VAT rate in force, falling back to the default rate until one is saved.
     */
    public function show(VatService $vatService)
    {
        return response()->json([
            /**
             * VAT rate, as a percentage.
             *
             * @example 19
             */
            'rate' => $vatService->rate(),
        ]);
    }

    /**
     * Update the VAT rate
     *
     * Sets the VAT rate applied to products and orders. It takes effect on the next product listing and
     * the next order; orders already placed keep the rate they were charged with.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            /**
             * VAT rate, as a percentage (19 means 19%).
             *
             * @example 19
             */
            'rate' => 'required|numeric|min:0|max:100',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => VatService::SETTINGS_KEY],
            [
                'value' => ['rate' => (float) $data['rate']],
                'content' => 'Tasa de IVA aplicada a productos y órdenes, en porcentaje',
            ]
        );

        return response()->json(['message' => 'Configuración actualizada correctamente']);
    }
}
