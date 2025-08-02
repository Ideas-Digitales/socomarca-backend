<?php
namespace App\Models\Policies;

use App\Models\User;
use App\Models\FavoriteList;

class FavoriteListPolicy
{
    public function viewAny(User $user)
    {
        return $user->hasPermissionTo('read-own-favorites-list');
    }

    public function view(User $user, FavoriteList $favoriteList)
    {
        return $user->id === $favoriteList->user_id
        && $user->hasPermissionTo('read-own-favorites-list');
    }

    public function create(User $user)
    {
        return $user->hasPermissionTo('create-favorites-list');
    }

    public function update(User $user, FavoriteList $favoriteList)
    {
        return $user->id === $favoriteList->user_id
        && $user->hasPermissionTo('update-favorites-list');
    }

    public function delete(User $user, FavoriteList $favoriteList)
    {
        return $user->id === $favoriteList->user_id
        && $user->hasPermissionTo('delete-favorites-list');
    }
}