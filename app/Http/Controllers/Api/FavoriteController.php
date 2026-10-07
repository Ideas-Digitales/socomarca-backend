<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Favorites\StoreRequest;
use App\Http\Resources\Favorites\FavoriteResource;
use App\Http\Resources\FavoritesList\FavoriteListResource;
use App\Models\Favorite;
use App\Models\FavoriteList;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Auth;

#[Group('Favorites', weight: 9)]
class FavoriteController extends Controller
{
    /**
     * List favorites by list
     *
     * Lists the authenticated user's favorite lists, each with its favorite products.
     */
    public function index()
    {

        $userId = Auth::user()->id;
        $lists = FavoriteList::with([
            'favorites.product.category',
            'favorites.product.subcategory'
        ])->where('user_id', $userId)->get();

        return FavoriteListResource::collection($lists);
    }

    /**
     * Add a product to a favorite list
     *
     * Adds a product unit to one of the authenticated user's favorite lists. Adding a product unit that
     * is already in the list returns the existing favorite. Lists of other users respond 403.
     */
    public function store(StoreRequest $request)
    {
        $data = $request->validated();
        $favorite = Favorite::updateOrCreate(
            [
                'favorite_list_id' => $data['favorite_list_id'],
                'product_id' => $data['product_id'],
                'unit' => $data['unit']
            ],
            []
        );
        return response()->json(new FavoriteResource($favorite), 201);
    }

    /**
     * Remove a favorite
     *
     * Removes a product unit from its favorite list. Only the owner of the list can remove it. Responds
     * 200 with an empty body.
     */
    public function destroy(Favorite $favorite)
    {
        $favorite->delete();
    }
}
