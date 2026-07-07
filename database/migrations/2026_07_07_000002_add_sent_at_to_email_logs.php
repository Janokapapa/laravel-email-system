<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_logs') || Schema::hasColumn('email_logs', 'sent_at')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            // Exact send timestamp — the warmup daily-cap counts by this, not
            // updated_at (which drifts on opens/clicks). Composite index scopes
            // the per-domain "sent today" query to today's sent rows.
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('email_logs') || !Schema::hasColumn('email_logs', 'sent_at')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'sent_at']);
            $table->dropColumn('sent_at');
        });
    }
};
