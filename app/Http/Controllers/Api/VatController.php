<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Siteinfo;
use App\Services\VatService;
use Illuminate\Http\Request;

class VatController extends Controller
{
    /**
     * Show the VAT rate in force
     *
     * @param VatService $vatService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(VatService $vatService)
    {
        return response()->json([
            'rate' => $vatService->rate(),
        ]);
    }

    /**
     * Update the VAT rate applied to products and orders
     *
     * The rate is stored as a percentage (19 means 19%) and takes effect on the
     * next product listing and the next order; orders already placed keep the rate
     * they were charged with.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $data = $request->validate([
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
