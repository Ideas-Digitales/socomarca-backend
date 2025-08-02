<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FavoritesList\DestroyRequest;
use App\Http\Requests\FavoritesList\IndexRequest;
use App\Http\Requests\FavoritesList\ShowRequest;
use App\Http\Requests\FavoritesList\StoreRequest;
use App\Http\Requests\FavoritesList\UpdateRequest;
use App\Http\Resources\Favorites\FavoriteResource;
use App\Http\Resources\FavoritesList\FavoriteListCollection;
use App\Http\Resources\FavoritesList\FavoriteListResource;
use App\Models\FavoriteList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FavoriteListController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', FavoriteList::class);

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

        return response()->json($favoriteList, 201);
    }

    public function show($id)
    {
        
        $favoriteList = FavoriteList::find($id);

        if (!$favoriteList) {
            return response()->json([
                'message' => 'Lista de favoritos no encontrada.',
            ], 404);
        }

        Gate::authorize('view', $favoriteList);
        return $favoriteList->toResource(FavoriteListResource::class);
    }

    public function update(UpdateRequest $updateRequest, $id)
    {
        $data = $updateRequest->validated();

        $favoriteList = FavoriteList::where('id', $id)
        ->where('user_id', Auth::user()->id)
        ->first();

        if (!$favoriteList)
        {
            return response()->json(
            [
                'message' => 'Lista de favoritos no encontrada.',
            ], 404);
        }

        $favoriteList->name = $data['name'];


        $favoriteList->save();

        return response()->json(['message' => 'La lista de favoritos seleccionada ha sido actualizada']);
    }

    public function destroy(DestroyRequest $destroyRequest, $id)
    {
        $destroyRequest->validated();
        
        $favoriteList = FavoriteList::where('id', $id)
        ->where('user_id', Auth::user()->id)
        ->first();

        if (!$favoriteList)
        {
            return response()->json(
            [
                'message' => 'Lista de favoritos no encontrada.',
            ], 404);
        }

        $favoriteList->delete();

        return response()->json(['message' => 'La lista de favoritos seleccionada ha sido eliminada']);
    }
}
