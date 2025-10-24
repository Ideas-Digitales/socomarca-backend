<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Siteinfo;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert CART_RESERVATION_TIMEOUT configuration
        Siteinfo::updateOrCreate(
            ['key' => 'CART_RESERVATION_TIMEOUT'],
            [
                'value' => ['timeout_minutes' => 1440], // Default: 24 hours (1440 minutes)
                'content' => 'Tiempo de expiración de reservas del carrito en minutos'
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove CART_RESERVATION_TIMEOUT configuration
        Siteinfo::where('key', 'CART_RESERVATION_TIMEOUT')->delete();
    }
};
