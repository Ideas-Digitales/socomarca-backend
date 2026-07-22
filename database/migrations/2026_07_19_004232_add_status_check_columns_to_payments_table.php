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
        Schema::table('payments', function (Blueprint $table) {
            $table
                ->unsignedTinyInteger('status_check_attempts')
                ->default(0)
                ->comment('Times the Webpay reconciliation job has queried this transaction\'s status');
            $table->timestamp('last_status_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['status_check_attempts', 'last_status_checked_at']);
        });
    }
};
