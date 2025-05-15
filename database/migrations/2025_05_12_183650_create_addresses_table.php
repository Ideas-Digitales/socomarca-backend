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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->foreignId('municipality_id')->constrained('municipalities')->onDelete('cascade');
            $table->string('postal_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->enum('type', ['billing', 'shipping'])->default('shipping');
            $table->string('phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
