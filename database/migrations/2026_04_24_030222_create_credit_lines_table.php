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
        Schema::create('credit_lines', function (Blueprint $table) {
            $table->id();
            $table
                ->string('branch_code')
                ->nullable()
                ->comment('Branch code SUEN');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table
                ->jsonb('state')
                ->nullable()
                ->comment('Credit line state');
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_lines');
    }
};
