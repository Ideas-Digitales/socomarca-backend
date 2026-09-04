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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('vat', 5, 2)
                ->default(0)
                ->comment('VAT rate applied to the order, as a percentage');
            $table->decimal('total', 10, 2)
                ->default(0)
                ->comment('Subtotal plus VAT, without the shipping cost');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)
                ->default(0)
                ->comment('price * quantity, without VAT');
            $table->decimal('vat', 5, 2)
                ->default(0)
                ->comment('VAT rate applied to the line, as a percentage');
            $table->decimal('total', 10, 2)
                ->default(0)
                ->comment('Line subtotal plus VAT');
        });

        // Existing orders were charged without VAT, so they keep a zero rate and a
        // total equal to the subtotal already stored (shipping still lives in amount).
        DB::table('order_items')->update([
            'subtotal' => DB::raw('price * quantity'),
            'total' => DB::raw('price * quantity'),
        ]);

        DB::table('orders')->update([
            'total' => DB::raw('subtotal'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['vat', 'total']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'vat', 'total']);
        });
    }
};
