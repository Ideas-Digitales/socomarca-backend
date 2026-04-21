<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('sucursal_code', 'branch_code');
            $table->char('random_user_type', 1)
                ->comment('Random user entity type: "C=Cliente|P=Proveedor|A=Ambos"')
                ->nullable();
            $table->string('user_code')
                ->comment('Random user code KOEN')
                ->nullable();
        });

        DB::table('users')
            ->update([
                'users.user_code' => DB::raw('users.rut')
            ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('user_code')
                ->comment('Random user code KOEN')
                ->nullable(false)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('branch_code', 'sucursal_code');
            $table->dropColumn('random_user_type');
            $table->dropColumn('user_code');
        });
    }
};
