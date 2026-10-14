<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add orders.customer_id (the user the order is placed for) and convert the existing
     * branches into users, so that every order points to its customer:
     *
     * 1. Users owning a primary branch get branch_type = P and the branch billing email
     * 2. Every secondary branch becomes a customer user (reusing the user with the same
     *    KOEN + SUEN, if any), without random_entity_id: the first sync links it by KOEN + SUEN
     * 3. orders.customer_id is the owner of a primary branch, the user of a secondary branch,
     *    or the user who placed the order when it has no branch
     *
     * orders.branch_id and the branches table are kept until Branch is no longer used.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->comment('User (Random entity + branch) the order is placed for')
                ->constrained('users')
                ->cascadeOnDelete();
        });

        $this->markPrimaryBranchUsers();
        $secondaryBranchUsers = $this->createSecondaryBranchUsers();
        $this->assignOrdersCustomer($secondaryBranchUsers);

        DB::statement('ALTER TABLE orders ALTER COLUMN customer_id SET NOT NULL');
    }

    /**
     * The users created for the secondary branches are kept: they may already have orders
     * or be linked to a Random entity.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }

    private function markPrimaryBranchUsers(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users
            SET branch_type = coalesce(users.branch_type, 'P'),
                billing_email = coalesce(users.billing_email, nullif(lower(trim(branches.email)), ''))
            FROM branches
            WHERE branches.user_id = users.id
              AND branches.branch_type = 'P'
        SQL);
    }

    /**
     * @return array<int, int> User ID by secondary branch ID
     */
    private function createSecondaryBranchUsers(): array
    {
        $customerRoleId = DB::table('roles')
            ->where('name', 'customer')
            ->where('guard_name', 'web')
            ->value('id');

        $usersByBranch = [];

        $branches = DB::table('branches')
            ->where('branch_type', 'S')
            ->orderBy('id')
            ->get();

        foreach ($branches as $branch) {
            $userCode = trim((string) $branch->user_code);
            $branchCode = trim((string) $branch->code);

            $userId = DB::table('users')
                ->whereRaw('trim(user_code) = ?', [$userCode])
                ->whereRaw("coalesce(trim(branch_code), '') = ?", [$branchCode])
                ->orderBy('id')
                ->value('id');

            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    'name' => trim((string) $branch->name),
                    'email' => $this->normalizeEmail($branch->commercial_email),
                    'billing_email' => $this->normalizeEmail($branch->email),
                    'phone' => trim((string) $branch->phone) ?: null,
                    'rut' => trim((string) $branch->rut),
                    'user_code' => $userCode,
                    'branch_code' => $branchCode,
                    'business_name' => trim((string) $branch->business_name),
                    'branch_type' => 'S',
                    'is_active' => true,
                    'password' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($customerRoleId !== null) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $customerRoleId,
                    'model_type' => 'App\Models\User',
                    'model_id' => $userId,
                ]);
            }

            $usersByBranch[$branch->id] = $userId;
        }

        return $usersByBranch;
    }

    /**
     * @param array<int, int> $secondaryBranchUsers User ID by secondary branch ID
     */
    private function assignOrdersCustomer(array $secondaryBranchUsers): void
    {
        DB::statement(<<<'SQL'
            UPDATE orders
            SET customer_id = branches.user_id
            FROM branches
            WHERE orders.branch_id = branches.id
              AND branches.branch_type = 'P'
              AND orders.customer_id IS NULL
        SQL);

        foreach ($secondaryBranchUsers as $branchId => $userId) {
            DB::table('orders')
                ->where('branch_id', $branchId)
                ->whereNull('customer_id')
                ->update(['customer_id' => $userId]);
        }

        DB::table('orders')
            ->whereNull('customer_id')
            ->update(['customer_id' => DB::raw('user_id')]);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }
};
