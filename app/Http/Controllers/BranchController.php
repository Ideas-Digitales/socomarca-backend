<?php

namespace App\Http\Controllers;

use App\Http\Resources\Branches\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BranchController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $branches = Branch::where('user_id', Auth::user()->id)
            ->get()
            ->paginate($perPage);

        return response()->json(
            BranchResource::collection($branches)
        );
    }

    public function show($id)
    {
        if (!\Illuminate\Support\Facades\DB::table('branches')->where('id', $id)->exists())
        {
            return response()->json(
            [
                'message' => 'Branch not found.', // TODO Change for language custom message in spanish
            ], 404);
        }

        $branch = Branch::find($id)->get();

        return response()->json(
            BranchResource::make($branch)
        );
    }
}
