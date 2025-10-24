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
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->string('unit'); // Mantener unidades múltiples
            $table->integer('stock')->default(0);
            $table->integer('reserved_stock')->default(0); // Para órdenes pendientes
            $table->integer('min_stock')->nullable(); // Stock mínimo
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id', 'unit']);
            $table->index(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};