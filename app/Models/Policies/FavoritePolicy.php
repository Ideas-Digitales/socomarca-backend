<?php
namespace App\Models\Policies;

use App\Models\User;
use App\Models\Favorite;

class FavoritePolicy
{
    public function view(User $user, Favorite $favorite)
    {
        return $user->id === $favorite->favoriteList->user_id
            && $user->hasPermissionTo('read-own-favorites');
    }

    public function create(User $user)
    {
        return $user->hasPermissionTo('create-favorites');
    }

    public function delete(User $user, Favorite $favorite)
    {
        return $user->id === $favorite->favoriteList->user_id
            && $user->hasPermissionTo('delete-favorites');
    }
}