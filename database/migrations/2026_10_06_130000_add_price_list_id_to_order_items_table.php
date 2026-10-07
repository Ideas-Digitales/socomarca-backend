<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing items keep a NULL price list: the list they were priced with was not recorded.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('price_list_id')
                ->nullable()
                ->after('price')
                ->comment('Random price list (prices.price_list_id) the item price was taken from');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_list_id');
        });
    }
};
