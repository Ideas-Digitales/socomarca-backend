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
        Schema::table('regions', function (Blueprint $table) {
            $table->string('random_name')->default('missing');
        });
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('random_name')->default('missing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('random_name');
        });
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn('random_name');
        });
    }
};
