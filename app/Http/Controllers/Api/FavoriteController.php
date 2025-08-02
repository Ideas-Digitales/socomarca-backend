<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Favorites\DestroyRequest;
use App\Http\Requests\Favorites\IndexRequest;
use App\Http\Requests\Favorites\StoreRequest;
use App\Http\Resources\Favorites\FavoriteCollection;
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

        Favorite::upsert([
                [
                    'favorite_list_id' => $data['favorite_list_id'],
                    'product_id' => $data['product_id'],
                    'unit' => $data['unit']
                ],
            ],
            uniqueBy: ['unit', 'favorite_list_id', 'product_id'],
            update: []
        );

        return response()->json(['message' => 'Producto agregado a favoritos'], 201);
    }

    public function destroy(DestroyRequest $destroyRequest, $id)
    {
        $destroyRequest->validated();

        $favorite = Favorite::where('id', $id)
        ->whereHas('favoriteList', function ($q) {
            $q->where('user_id', Auth::user()->id);
        })
        ->first();

        if (!$favorite)
        {
            return response()->json(
            [
                'message' => 'Producto favorito no encontrado',
            ], 404);
        }

        $favorite->delete();

        return response()->json(['message' => 'El producto favorito seleccionado ha sido eliminado']);
    }
}
