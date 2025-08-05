<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FavoritesList\StoreRequest;
use App\Http\Requests\FavoritesList\UpdateRequest;
use App\Http\Resources\FavoritesList\FavoriteListCollection;
use App\Http\Resources\FavoritesList\FavoriteListResource;
use App\Models\FavoriteList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteListController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $favoritesList = FavoriteList::where('user_id', $user->id)->get();
        return new FavoriteListCollection($favoritesList);
    }

    public function store(StoreRequest $storeRequest)
    {
        $data = $storeRequest->validated();

        $favoriteList = new FavoriteList;

        $favoriteList->name = $data['name'];
        $favoriteList->user_id = Auth::user()->id;

        $favoriteList->save();

        return response()->json(
            $favoriteList->toResource(FavoriteListResource::class),
            201
        );
    }

    public function show(FavoriteList $favoriteList)
    {
        return $favoriteList->toResource(FavoriteListResource::class);
    }

    public function update(UpdateRequest $updateRequest, FavoriteList $favoriteList)
    {
        $data = $updateRequest->validated();
        $favoriteList->name = $data['name'];
        $favoriteList->save();
        return $favoriteList->toResource(FavoriteListResource::class);
    }

    public function destroy(FavoriteList $favoriteList)
    {
        $favoriteList->delete();
    }
}
