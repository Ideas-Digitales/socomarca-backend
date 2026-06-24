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
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index(['code', 'user_code'], 'branch_suen_koen_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
