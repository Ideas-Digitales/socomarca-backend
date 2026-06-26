<?php

namespace App\Http\Controllers;

use App\Http\Resources\Branches\BranchResource;
use App\Models\Branch;
use App\Models\Scopes\SecondaryBranchesScope;
use Illuminate\Http\Request;

class BranchController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);

        return BranchResource::collection(
            Branch::secondary()->paginate($perPage)
        );
    }

    public function show($id)
    {
        return BranchResource::make(
            Branch::secondary()->findOrFail($id)
        );
    }
}
