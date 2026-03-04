<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ViewedNotification;

class ViewedNotificationPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-viewed-notifications');
    }
}
