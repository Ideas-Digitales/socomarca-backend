<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const BRANCH_PERMISSIONS = ['read-all-branches', 'read-own-branches'];

    /**
     * Drop orders.branch_id and the branches table: every branch is a user since
     * 2026_10_06_120000_add_customer_id_to_orders_table, and orders point to it with customer_id.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_branch_id');
            $table->dropColumn('branch_id');
        });

        Schema::drop('branches');

        DB::table(config('permission.table_names.permissions'))
            ->whereIn('name', self::BRANCH_PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Restore the schema only: the branches data, the orders branch and the permissions are not recovered.
     */
    public function down(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('RANDOM: NOKOEN');
            $table->string('code')->nullable()->comment('RANDOM: SUEN');
            $table->string('user_code')->nullable()->comment('RANDOM: KOEN');
            $table->string('email')->comment('RANDOM: EMAIL');
            $table->string('commercial_email')->comment('RANDOM: EMAILCOMER');
            $table->string('phone')->nullable()->comment('RANDOM: FOEN');
            $table->string('rut')->comment('RANDOM: RTEN');
            $table->string('business_name')->nullable()->comment('RANDOM: SIEN');
            $table->string('branch_type')->default('S')->comment('P=Principal, S=Secondary');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['code', 'user_code'], 'branch_suen_koen_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(
                    table: 'branches',
                    indexName: 'orders_branch_id',
                )
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }
};
