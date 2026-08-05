<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow an audience row to have no e-mail address.
 *
 * An SMS list is a list of phone numbers; it has no addresses at all. With
 * email NOT NULL such a file could not be imported — every row failed at the
 * database, after passing validation, which is the worst place to find out.
 *
 * The (email_audience_group_id, email) unique index stays: MySQL does not treat
 * two NULLs as equal, so several phone-only rows coexist in one group while a
 * duplicate address is still rejected. Phone-only duplicates are caught in the
 * importer, which matches on phone when there is no address.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audience_users') || !Schema::hasColumn('audience_users', 'email')) {
            return;
        }

        // Raw DDL rather than doctrine/dbal: the column keeps its exact type and
        // collation, and the change is a single statement on a large table.
        DB::statement('ALTER TABLE `audience_users` MODIFY `email` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('audience_users') || !Schema::hasColumn('audience_users', 'email')) {
            return;
        }

        // Rows imported without an address would block the NOT NULL, so give
        // them a placeholder derived from the phone instead of failing the
        // rollback outright.
        DB::statement("UPDATE `audience_users` SET `email` = CONCAT('no-email-', id, '@invalid.local') WHERE `email` IS NULL");
        DB::statement('ALTER TABLE `audience_users` MODIFY `email` VARCHAR(255) NOT NULL');
    }
};
