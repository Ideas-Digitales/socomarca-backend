<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        });
    }
};
