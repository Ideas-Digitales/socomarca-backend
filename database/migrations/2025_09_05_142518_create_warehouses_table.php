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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('business_code'); // EMPRESA
            $table->string('branch_code');   // KOSU
            $table->string('warehouse_code')->unique(); // KOBO
            $table->string('name');          // NOKOBO
            $table->text('address')->nullable(); // DIBO
            $table->string('phone')->nullable(); // FOBO
            $table->integer('priority')->default(999); // 1 = bodega por defecto
            $table->boolean('is_active')->default(true);
            $table->boolean('no_explosion')->default(false); // NOEXPLOSI
            $table->boolean('no_lot')->default(true);        // SINLOTE
            $table->boolean('no_location')->default(true);   // SINUBIC
            $table->string('warehouse_type')->nullable();    // TIPOBODE
            $table->timestamps();

            $table->index(['warehouse_code']);
            $table->index(['priority', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};