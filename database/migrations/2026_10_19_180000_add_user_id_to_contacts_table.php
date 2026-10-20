<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts created before the branch user existed keep `branch_code`. This assigns each one
     * to the matching branch user and then drops that column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'user_id')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('contacts', 'branch_code')) {
            DB::statement(<<<'SQL'
                UPDATE contacts AS c
                SET user_id = matched.id
                FROM (
                    SELECT DISTINCT ON (c2.id) c2.id AS contact_id, u.id
                    FROM contacts c2
                    JOIN users u ON trim(u.user_code) = trim(c2.entity_code)
                    WHERE c2.user_id IS NULL
                      AND c2.branch_code IS NOT NULL
                      AND left(coalesce(u.branch_code, ''), 3) = c2.branch_code
                    ORDER BY c2.id,
                        CASE WHEN u.branch_code = c2.branch_code THEN 0 ELSE 1 END,
                        CASE WHEN u.branch_type = 'P' THEN 0 ELSE 1 END,
                        u.id
                ) AS matched
                WHERE c.id = matched.contact_id
            SQL);
        }

        DB::statement(<<<'SQL'
            UPDATE contacts AS c
            SET user_id = matched.id
            FROM (
                SELECT DISTINCT ON (c2.id) c2.id AS contact_id, u.id
                FROM contacts c2
                JOIN users u ON trim(u.user_code) = trim(c2.entity_code)
                WHERE c2.user_id IS NULL
                ORDER BY c2.id,
                    CASE WHEN u.branch_type = 'P' THEN 0 ELSE 1 END,
                    u.id
            ) AS matched
            WHERE c.id = matched.contact_id
        SQL);

        DB::table('contacts')->whereNull('user_id')->delete();

        if (Schema::hasColumn('contacts', 'branch_code')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('branch_code');
            });
        }

        DB::statement('ALTER TABLE contacts ALTER COLUMN user_id SET NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'branch_code')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('branch_code', 3)->nullable();
            });
        }

        if (Schema::hasColumn('contacts', 'user_id')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
