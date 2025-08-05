<?php
namespace App\Models\Policies;

use App\Models\User;
use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoritePolicy
{
    public function viewAny(User $user)
    {
        if ($user->hasPermissionTo('read-own-favorites'))
        {
            return true;
        }

        return false;
    }

    public function create(User $user)
    {
        $favoriteListId = request()->input('favorite_list_id');
        $favoriteList = \App\Models\FavoriteList::find($favoriteListId);

        if (!$favoriteList) {
            return true; // Esto permitirá un error de validación
        }

        return $favoriteList->user_id === $user->id
            && $user->hasPermissionTo('create-favorites');
    }

    public function delete(User $user, Favorite $favorite)
    {
        return $user->id === $favorite->favoriteList->user_id
            && $user->hasPermissionTo('delete-favorites');
    }
}
