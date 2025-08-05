<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Favorites\StoreRequest;
use App\Http\Resources\Favorites\FavoriteResource;
use App\Http\Resources\FavoritesList\FavoriteListResource;
use App\Models\Favorite;
use App\Models\FavoriteList;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    public function index(){

        $userId = Auth::user()->id;
        $lists = FavoriteList::with([
            'favorites.product.category',
            'favorites.product.subcategory'
        ])->where('user_id', $userId)->get();

        return FavoriteListResource::collection($lists);
    }

    public function store(StoreRequest $request)
    {
        $data = $request->validated();
        $favoriteId = Favorite::upsert([
                [
                    'favorite_list_id' => $data['favorite_list_id'],
                    'product_id' => $data['product_id'],
                    'unit' => $data['unit']
                ],
            ],
            uniqueBy: ['unit', 'favorite_list_id', 'product_id'],
            update: []
        );

        $favorite = Favorite::findOrFail($favoriteId);
        return response()->json(new FavoriteResource($favorite), 201);
    }

    public function destroy(Favorite $favorite)
    {
        $favorite->delete();
    }
}
