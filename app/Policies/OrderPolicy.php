<?php

namespace App\Policies;

use App\Enums\BranchType;
use App\Models\Order;
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

    /**
     * The user placed the order (`orders.user_id`). The branch it was placed for cannot
     * cancel it. `read-all-orders` together with `cancel-orders` cancels any order.
     */
    public function cancel(User $user, Order $order): bool
    {
        if ($user->hasAllPermissions(['read-all-orders', 'cancel-orders'])) {
            return true;
        }

        return (int) $order->user_id === (int) $user->id;
    }
}
