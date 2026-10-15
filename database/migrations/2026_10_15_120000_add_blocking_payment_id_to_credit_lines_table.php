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
        Schema::table('credit_lines', function (Blueprint $table) {
            $table->foreignId('blocking_payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete()
                ->comment('Credit payment that blocked the line until RAPI invoices its NVV');
        });

        // Lines blocked before this column existed are attributed to the latest processing
        // credit payment of their user, the one the checkout blocked them for.
        DB::table('credit_lines')
            ->where('is_blocked', true)
            ->orderBy('id')
            ->each(function (object $creditLine) {
                $paymentId = DB::table('payments')
                    ->join('orders', 'orders.id', '=', 'payments.order_id')
                    ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                    ->where('orders.user_id', $creditLine->user_id)
                    ->where('payment_methods.code', 'random_credit')
                    ->where('payments.status', 'processing')
                    ->max('payments.id');

                if ($paymentId !== null) {
                    DB::table('credit_lines')
                        ->where('id', $creditLine->id)
                        ->update(['blocking_payment_id' => $paymentId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blocking_payment_id');
        });
    }
};
