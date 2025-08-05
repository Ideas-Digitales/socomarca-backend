<?php

namespace App\Policies;

use App\Models\Faq;
use App\Models\User;

class FaqPolicy
{
    public function viewAny(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        return $user->can('read-all-faqs');
    }

    public function view(?User $user, Faq $faq): bool
    {
        if ($user === null) {
            return true;
        }

        return $user->can('read-all-faqs');
    }

    public function create(User $user): bool
    {
        return $user->can('create-faqs');
    }

    public function update(User $user, Faq $faq): bool
    {
        return $user->can('update-faqs');
    }

    public function delete(User $user, Faq $faq): bool
    {
        return $user->can('delete-faqs');
    }

    public function restore(User $user, Faq $faq): bool
    {
        return false;
    }

    public function forceDelete(User $user, Faq $faq): bool
    {
        return false;
    }
}