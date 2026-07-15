<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_sending_domains')) {
            return;
        }
        if (Schema::hasColumn('email_sending_domains', 'blocked_providers')) {
            return;
        }

        Schema::table('email_sending_domains', function (Blueprint $table) {
            // Per-domain provider suppression: recipients whose provider group
            // (ProviderResolver) is listed here are NOT handed to PMTA — the send
            // is deferred (left 'spooled'). Used to pause a provider for a domain
            // whose reputation with that provider is damaged (e.g. Gmail 5.7.1
            // spam-block), while keeping the other providers flowing.
            // null/empty => nothing blocked.
            $table->json('blocked_providers')->nullable()->after('max_daily');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_sending_domains') && Schema::hasColumn('email_sending_domains', 'blocked_providers')) {
            Schema::table('email_sending_domains', function (Blueprint $table) {
                $table->dropColumn('blocked_providers');
            });
        }
    }
};
