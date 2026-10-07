<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FavoritesList\StoreRequest;
use App\Http\Requests\FavoritesList\UpdateRequest;
use App\Http\Resources\FavoritesList\FavoriteListCollection;
use App\Http\Resources\FavoritesList\FavoriteListResource;
use App\Models\FavoriteList;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group('Favorites', 'Named lists of favorite products (per sale unit) of the authenticated user.', weight: 9)]
class FavoriteListController extends Controller
{
    /**
     * List favorite lists
     *
     * Lists the authenticated user's favorite lists, without their products.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $favoritesList = FavoriteList::where('user_id', $user->id)->get();
        return new FavoriteListCollection($favoritesList);
    }

    /**
     * Create a favorite list
     *
     * Creates an empty favorite list for the authenticated user.
     */
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

    /**
     * Show a favorite list
     *
     * Shows a favorite list with its products. Only the owner of the list can see it.
     */
    public function show(FavoriteList $favoriteList)
    {
        return $favoriteList->toResource(FavoriteListResource::class);
    }

    /**
     * Rename a favorite list
     *
     * Only the owner of the list can rename it.
     */
    public function update(UpdateRequest $updateRequest, FavoriteList $favoriteList)
    {
        $data = $updateRequest->validated();
        $favoriteList->name = $data['name'];
        $favoriteList->save();
        return $favoriteList->toResource(FavoriteListResource::class);
    }

    /**
     * Delete a favorite list
     *
     * Deletes the list together with its favorites. Only the owner of the list can delete it. Responds 200
     * with an empty body.
     */
    public function destroy(FavoriteList $favoriteList)
    {
        $favoriteList->delete();
    }
}
