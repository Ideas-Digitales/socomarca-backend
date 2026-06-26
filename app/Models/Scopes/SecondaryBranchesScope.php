<?php

namespace App\Models\Scopes;

use App\Enums\BranchType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SecondaryBranchesScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('branch_type', BranchType::SECONDARY);
    }
}
