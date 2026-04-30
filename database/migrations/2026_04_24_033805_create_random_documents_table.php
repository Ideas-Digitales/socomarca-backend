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
        Schema::create('random_documents', function (Blueprint $table) {
            $table->bigInteger('idmaeedo');
            $table
                ->string('type')
                ->comment('Document type: NVV, FCV, etc.');
            $table
                ->jsonb('document')
                ->comment('Json random document');
            $table->timestamps();
            $table->primary('idmaeedo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('random_documents');
    }
};
