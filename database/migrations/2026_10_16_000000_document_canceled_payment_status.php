<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `payments.status` is a string. `canceled` is the status of a credit payment
     * canceled while the order was still waiting for an FCV invoice.
     */
    public function up(): void
    {
        DB::statement("COMMENT ON COLUMN payments.status IS 'Payment status: processing, completed, failed, canceled'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN payments.status IS 'Payment status: processing, completed, failed'");
    }
};
