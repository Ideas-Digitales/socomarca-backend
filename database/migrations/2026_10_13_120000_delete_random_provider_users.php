<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Delete the users created from Random provider entities (TIEN = P) by previous syncs. The
     * current sync only syncs customer entities, so they are no longer managed by it.
     *
     * Users with orders are kept, since deleting a user cascades to its orders, as well as users
     * with a role other than customer (e.g. admin).
     */
    public function up(): void
    {
        $morphClass = (new User)->getMorphClass();

        $hasOrders = DB::table('users')
            ->where('random_user_type', 'P')
            ->where(function ($query) {
                $query->whereExists(fn ($orders) => $orders->from('orders')->whereColumn('orders.user_id', 'users.id'))
                    ->orWhereExists(fn ($orders) => $orders->from('orders')->whereColumn('orders.customer_id', 'users.id'));
            })
            ->pluck('id');

        if ($hasOrders->isNotEmpty()) {
            Log::warning('Random provider users with orders were not deleted', ['user_ids' => $hasOrders->all()]);
        }

        $userIds = DB::table('users')
            ->where('random_user_type', 'P')
            ->whereNotIn('id', $hasOrders)
            ->whereNotExists(fn ($roles) => $roles->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', $morphClass)
                ->where('roles.name', '<>', 'customer'))
            ->pluck('id');

        foreach ($userIds->chunk(1000) as $ids) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $morphClass)
                ->whereIn('tokenable_id', $ids)
                ->delete();
            DB::table('model_has_roles')
                ->where('model_type', $morphClass)
                ->whereIn('model_id', $ids)
                ->delete();
            DB::table('model_has_permissions')
                ->where('model_type', $morphClass)
                ->whereIn('model_id', $ids)
                ->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * The deleted users cannot be restored.
     */
    public function down(): void
    {
        //
    }
};
