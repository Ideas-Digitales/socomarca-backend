<?php

namespace App\Policies;

use App\Enums\BranchType;
use App\Models\User;

class OrderPolicy
{
    /**
     * Whether the buyer can place an order for the customer: for itself, or, being a
     * primary branch, for an active secondary branch of its own Random entity (KOEN).
     */
    public function placeFor(User $buyer, User $customer): bool
    {
        if ($buyer->is($customer)) {
            return true;
        }

        return $customer->is_active
            && $buyer->branch_type === BranchType::PRIMARY
            && $buyer->user_code === $customer->user_code
            && $customer->branch_type === BranchType::SECONDARY;
    }
}
