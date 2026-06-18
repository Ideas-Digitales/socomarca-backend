<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->update(['subcategory_id' => null]);

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supercategory_id')
                ->nullable()
                ->after('category_id')
                ->constrained('categories')
                ->nullOnDelete();

            $table->dropForeign(['subcategory_id']);

            $table->foreign('subcategory_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['subcategory_id']);

            $table->foreign('subcategory_id')
                ->references('id')
                ->on('subcategories')
                ->nullOnDelete();

            $table->dropForeign(['supercategory_id']);
            $table->dropColumn('supercategory_id');
        });
    }
};
