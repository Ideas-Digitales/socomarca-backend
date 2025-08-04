<?php

namespace App\Services\Data;

use App\Models\User;

class UserService
{
    public $authorizationReadFilters = [];
    public function addAuthorizationReadFilter($closure)
    {
        $this->authorizationReadFilters[] = $closure;
    }

    public function getPaginatedUsers($sort, $sortDirection, $perPage)
    {
        $query = User::with('roles');

        foreach ($this->authorizationReadFilters as $filter) {
            $query = $query->when($filter, $filter);
        }

        return $query->orderBy($sort, $sortDirection)
            ->paginate($perPage);
    }
}
