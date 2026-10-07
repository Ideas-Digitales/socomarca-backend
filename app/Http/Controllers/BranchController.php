<?php

namespace App\Http\Controllers;

use App\Http\Resources\Branches\BranchResource;
use App\Models\Order;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

#[Group('Branches', 'Branches of the authenticated customer\'s Random ERP entity that it can place orders for.', weight: 3)]
class BranchController extends Controller
{
    /**
     * List orderable branches
     *
     * Branches the authenticated user can place orders for besides itself, with their addresses,
     * ordered by name. Only primary branches (`branch_type` `P`) get results: the active secondary
     * branches of their Random ERP entity. For other users the list is empty.
     */
    #[QueryParameter('per_page', 'Branches per page.', type: 'int', default: 20)]
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);

        return BranchResource::collection(
            $request->user()
                ->orderableBranches()
                ->with('addresses.municipality.region')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($perPage)
        );
    }

    /**
     * Show a branch
     *
     * A branch the authenticated user can place orders for (itself or one of its orderable branches),
     * with its addresses. Branches it cannot order for respond 404, as nonexistent ones, so that their
     * existence is not revealed.
     */
    public function show(Request $request, User $branch)
    {
        abort_unless($request->user()->can('placeFor', [Order::class, $branch]), 404);

        return BranchResource::make($branch->load('addresses.municipality.region'));
    }
}
