<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RandomApiService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

#[Group('Credit lines', 'Credit line of a customer branch in Random ERP.', weight: 14)]
class CreditLineController extends Controller
{
    public function __construct(
        private RandomApiService $randomApiService
    ) {}

    /**
     * Show the credit line of a user
     *
     * Credit line of the user's Random ERP branch (`user_code` + `branch_code`), with the fields returned
     * by Random ERP: `CRSD` is the credit limit and `CRSDVU` the used amount, so the available credit is
     * `CRSD - CRSDVU`. Users with `read-all-credit-lines` can see any user's credit line; users with
     * `read-own-credit-lines` only their own.
     *
     * After a credit payment the credit line is `blocked` until the pending payment is processed: the
     * response is then the locally stored state, which already includes that order in `CRSDVU`, and
     * Random ERP is not queried. Otherwise (`unblocked`) the data comes from Random ERP.
     *
     * Random ERP errors respond `{message, detail}`: 404 when Random ERP has no credit line for the
     * branch, 401/403 when the authentication with Random ERP fails and 500 otherwise.
     *
     * @response array{KOEN: string, SUEN: string, CRSD: float, CRSDVU: float, CRSDVV: float, CRSDCU: float, CRSDCV: float, status: 'blocked'|'unblocked'}
     */
    #[Response(404, 'The user does not exist, or Random ERP has no credit line for its branch', type: 'array{message: string}|array{message: "No se pudo obtener el crédito del cliente", detail: "Recurso no encontrado en Random API"}')]
    #[Response(500, 'Random ERP failed or returned an invalid credit line', type: 'array{message: "No se pudo obtener el crédito del cliente", detail: "Error de comunicación con el servicio de Random API"}')]
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
