<?php

namespace App\Models\Policies;

use App\Models\User;

class CreditLinePolicy
{
    /**
     * Determine whether the user can view the credit line.
     */
    public function view(User $user, User $model): bool
    {
        // Un usuario puede ver su propia línea de crédito
        if ($user->id === $model->id) {
            return $user->can('read-own-credit-lines');
        }

        return false;

        // Admins pueden ver cualquier línea de crédito
        // usar en caso que se solicite como requerimiento obtener varias lineas de credito
        //return $user->can('read-all-credit-lines');
    }
}
