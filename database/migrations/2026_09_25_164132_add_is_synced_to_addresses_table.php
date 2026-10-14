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
        Schema::table('addresses', function (Blueprint $table) {
            $table->boolean('is_synced')
                ->default(false)
                ->comment('Address managed by the Random entities sync');
        });

        DB::statement('CREATE UNIQUE INDEX addresses_user_id_synced_unique ON addresses (user_id) WHERE is_synced');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS addresses_user_id_synced_unique');

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('is_synced');
        });
    }
};
