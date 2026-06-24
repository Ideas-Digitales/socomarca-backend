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
            $payload = $creditLine->state;
            $payload['status'] = 'blocked';
            return response()->json($payload);
        }

        $response = $this->randomApiService->getCreditLine(
            $user->user_code,
            $user->branch_code
        );

        $data = $response->json();
        $data['status'] = 'unblocked';
        return response()->json($data);
    }
}
