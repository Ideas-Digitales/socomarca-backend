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
        $branchCode = $user->branch_code;
        $creditLine = $user
            ->creditLines()
            ->where('branch_code', $branchCode)
            ->first();

        if ($creditLine && $creditLine->is_blocked) {
            return response()->json($creditLine->state);
        }

        $response = $this->randomApiService->getCreditLine(
            $user->rut,
            $user->branch_code
        );

        $data = $response->json();
        return response()->json($data);
    }
}
