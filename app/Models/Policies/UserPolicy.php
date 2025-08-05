<?php

namespace App\Models\Policies;

use App\Models\User;
use App\Services\Data\UserService;

class UserPolicy
{
    public function __construct(private readonly UserService $service)
    {
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if (
            !$user->can('read-users')
            && !$user->can('read-admin-users')
        ) {
            return false;
        }

        if (!$user->can('read-admin-users')) {
            $f = function ($query) {
                return $query->withoutRole(['superadmin', 'admin']);
            };
            $this->service->addAuthorizationReadFilter($f);
        }

        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        if ($model->hasRole(['superadmin', 'admin'])) {
            return $user->can('read-admin-users');
        } else {
            return $user->can('read-users');
        }
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create-users') || $user->can('create-admin-users');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $this->view($user, $model) && $user->can('update-users');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        if (!($this->view($user, $model) && $user->can('delete-users'))) {
            return false;
        }

        if ($model->id === $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
