<?php

namespace App\Http\Controllers;

use App\Http\Resources\Branches\BranchResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Branches the authenticated user can place orders for, with their addresses.
     */
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
     * A branch the authenticated user can place orders for, with its addresses. Branches it
     * cannot order for respond 404, as nonexistent ones, so that their existence is not revealed.
     */
    public function show(Request $request, User $branch)
    {
        abort_unless($request->user()->can('placeFor', [Order::class, $branch]), 404);

        return BranchResource::make($branch->load('addresses.municipality.region'));
    }
}
