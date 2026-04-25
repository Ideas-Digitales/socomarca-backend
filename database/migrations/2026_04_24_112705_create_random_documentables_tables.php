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
        Schema::create('random_documentables', function (Blueprint $table) {
            $table
                ->foreignId('random_document_id')
                ->constrained('random_documents', 'idmaeedo')
                ->onDelete('cascade');
            $table->bigInteger('documentable_id');
            $table->string('documentable_type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('random_documentables');
    }
};
