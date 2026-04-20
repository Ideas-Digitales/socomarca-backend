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
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('code')->nullable()->after('id');
        });

        $methods = [
            'Transbank' => 'transbank',
            'Paypal' => 'paypal',
            'Stripe' => 'stripe',
            'Servipag' => 'servipag',
            'MercadoPago' => 'mercadopago',
            'Crédito Random' => 'random_credit',
        ];

        foreach ($methods as $name => $code) {

            DB::table('payment_methods')
                ->where('name', $name)
                ->update(['code' => $code]);
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('code')
                ->unique()
                ->after('id')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
