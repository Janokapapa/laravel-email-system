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
        if (Schema::hasColumn('email_logs', 'provider_message_id')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            // The SMS provider's own id for the message, returned when it accepts
            // the send. It is the only reliable key a delivery report can be
            // matched on: the recipient number alone is ambiguous once the same
            // number appears in two campaigns, and it arrives formatted
            // differently from what was imported. Without this column a report
            // can only be logged, never applied, so campaign statistics stay at
            // "100% sent" forever.
            $table->string('provider_message_id')->nullable()->after('status');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'provider_message_id')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropIndex(['provider_message_id']);
                $table->dropColumn('provider_message_id');
            });
        }
    }
};
