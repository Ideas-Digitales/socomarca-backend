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
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('random_entity_id')
                ->nullable()
                ->unique()
                ->comment('Random entity record ID IDMAEEN (entity + branch)');
            $table->char('branch_type', 1)
                ->nullable()
                ->comment('Random branch type TIPOSUC: "P=Principal|S=Secundaria"');
            $table->string('billing_email')
                ->nullable()
                ->comment('Random billing email EMAIL');
            $table->timestamp('random_synced_at')
                ->nullable()
                ->comment('Last time the user was synced from Random');

            $table->dropUnique('users_rut_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_random_entity_user_code_check
                CHECK (random_entity_id IS NULL OR (user_code IS NOT NULL AND trim(user_code) <> ''))
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_random_entity_user_code_check');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('rut', 'users_rut_unique');

            $table->dropUnique(['random_entity_id']);
            $table->dropColumn([
                'random_entity_id',
                'branch_type',
                'billing_email',
                'random_synced_at',
            ]);
        });
    }
};
