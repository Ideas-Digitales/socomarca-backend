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
            $table->string('random_key', 12)->default('missing');
        });
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('random_key', 12)->default('missing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('random_key');
        });
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn('random_key');
        });
    }
};
