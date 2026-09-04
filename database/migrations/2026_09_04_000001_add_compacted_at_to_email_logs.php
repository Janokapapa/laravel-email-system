<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_logs')) {
            return;
        }
        if (Schema::hasColumn('email_logs', 'compacted_at')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            // Marks a row whose bulky text columns (message, error,
            // bounce_reason) were emptied by email-system:compact-logs. The
            // statistics stay intact, only the body is gone. Without this
            // marker the command cannot tell an already compacted row from one
            // that legitimately never had a body, so every daily run would
            // rewrite the same hundreds of thousands of rows forever.
            $table->timestamp('compacted_at')->nullable()->after('updated_at');

            // The command selects on "not yet compacted AND older than the
            // cutoff"; compacted_at first makes the index usable for it.
            $table->index(['compacted_at', 'created_at'], 'idx_email_logs_compacted');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'compacted_at')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropIndex('idx_email_logs_compacted');
                $table->dropColumn('compacted_at');
            });
        }
    }
};
