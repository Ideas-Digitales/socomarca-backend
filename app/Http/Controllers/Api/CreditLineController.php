<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CreditLineController extends Controller
{
    public function __construct(
        private RandomApiService $randomApiService
    ) {
    }

    public function show(User $user): JsonResponse
    {
        try {
            $data = $this->randomApiService->getCreditLine(
                $user->rut,
                $user->sucursal_code
            );

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error al obtener línea de crédito: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al obtener la línea de crédito',
                'error'   => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }
}
