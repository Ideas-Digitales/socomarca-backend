<?php

namespace App\Models\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('read-all-addresses') || $user->can('read-own-addresses');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Address $address): bool
    {
        if ($user->can('read-all-addresses')) {
            return true;
        } elseif (!$user->can('read-own-addresses')) {
            return false;
        }

        return $user->id === $address->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create-addresses');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Address $address): bool
    {
        if (!$user->can('update-addresses')) {
            return false;
        };

        return $this->view($user, $address);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Address $address): bool
    {
        if (!$user->can('delete-addresses')) {
            return false;
        };

        return $this->view($user, $address);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Address $address): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Address $address): bool
    {
        return false;
    }
}
