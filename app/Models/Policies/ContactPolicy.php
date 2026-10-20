<?php

namespace App\Models\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('read-all-contacts') || $user->can('read-own-contacts');
    }

    public function view(User $user, Contact $contact): bool
    {
        if ($user->can('read-all-contacts')) {
            return true;
        }

        if (! $user->can('read-own-contacts')) {
            return false;
        }

        return $user->canUseContact($contact);
    }

    public function create(User $user): bool
    {
        return $user->can('create-contacts');
    }

    public function update(User $user, Contact $contact): bool
    {
        if (! $user->can('update-contacts')) {
            return false;
        }

        return $this->view($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return false;
    }

}
