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
    ) {}

    public function show(User $user): JsonResponse
    {
        $response = $this->randomApiService->getCreditLine(
            $user->rut,
            $user->branch_code
        );

        $data = $response->json();
        return response()->json($data);
    }
}
