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
            $table->foreignId('region_id')
                ->nullable()
                ->after('user_id')
                ->constrained(
                    table: 'regions',
                    indexName: 'addresses_region_id',
                )
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::statement('
            UPDATE addresses
            SET region_id = municipalities.region_id
            FROM municipalities
            WHERE addresses.municipality_id = municipalities.id
              AND addresses.region_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign('addresses_region_id');
            $table->dropColumn('region_id');
        });
    }
};
