<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 2025 contacts table was unused. Contacts are natural persons of a Random ERP
     * entity (KOEN), shared by every branch of that entity.
     */
    public function up(): void
    {
        Schema::dropIfExists('contacts');

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_code');
            $table->string('rut');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->string('position')->nullable();
            $table->string('area')->nullable();
            $table->boolean('authorized')->default(false);
            $table->string('address')->nullable();
            $table->timestamp('random_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_code', 'rut']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }
};
