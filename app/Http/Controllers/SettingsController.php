<?php

namespace App\Http\Controllers;

use App\Models\Siteinfo;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // Muestra la configuración actual
    public function index()
    {
        $settings = Siteinfo::where('key', 'prices_settings')->first();
        return response()->json([
            'min_max_quantity_enabled' => $settings ? ($settings->value['min_max_quantity_enabled'] ?? false) : false,
        ]);
    }

    // Actualiza la configuración
    public function update(Request $request)
    {
        $data = $request->validate([
            'min_max_quantity_enabled' => 'required|boolean',
        ]);

        Siteinfo::updateOrCreate(
            ['key' => 'prices_settings'],
            ['value' => $data]
        );

        return response()->json(['message' => 'Configuración actualizada correctamente']);
    }

    /**
     * Get cart reservation timeout configuration
     */
    public function getCartReservationTimeout()
    {
        $config = Siteinfo::where('key', 'CART_RESERVATION_TIMEOUT')->first();

        if (!$config) {
            return response()->json([
                'data' => [
                    'timeout_minutes' => 1440, // Default: 24 hours
                    'content' => 'Tiempo de expiración de reservas del carrito en minutos'
                ]
            ]);
        }

        return response()->json([
            'data' => [
                'timeout_minutes' => $config->value['timeout_minutes'] ?? 1440,
                'content' => $config->content
            ]
        ]);
    }

    /**
     * Update cart reservation timeout configuration
     * Requires update-system-config permission
     */
    public function updateCartReservationTimeout(Request $request)
    {
        $data = $request->validate([
            'timeout_minutes' => 'required|integer|min:1|max:10080', // Min 1 minute, Max 7 days
        ]);

        $value = [
            'timeout_minutes' => $data['timeout_minutes'],
        ];

        Siteinfo::updateOrCreate(
            ['key' => 'CART_RESERVATION_TIMEOUT'],
            [
                'value' => $value,
                'content' => 'Tiempo de expiración de reservas del carrito en minutos'
            ]
        );

        return response()->json([
            'data' => $value
        ]);
    }
}
