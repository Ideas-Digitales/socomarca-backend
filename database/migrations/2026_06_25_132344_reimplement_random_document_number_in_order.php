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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('random_document_number')
                ->nullable()
                ->unique()
                ->comment('Random Document: "numero"');
            $table->dropColumn('internal_sale_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('internal_sale_note')
                ->nullable()
                ->comment('Sale note document NVV IDMAEEDO');
            $table->dropColumn('random_document_number');
        });
    }
};
