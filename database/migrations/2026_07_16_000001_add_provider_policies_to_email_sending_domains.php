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
        if (Schema::hasColumn('email_sending_domains', 'provider_policies')) {
            return;
        }

        Schema::table('email_sending_domains', function (Blueprint $table) {
            // Per-domain, per-provider RE-WARM policy (softer than blocked_providers).
            // Shape: { "gmail": { "daily_cap": 25, "engaged_days": 180 } }
            // Meaning: hand at most daily_cap/day of this provider's recipients to
            // PMTA, and ONLY recipients engaged (clicked/opened) within engaged_days.
            // Everyone else for that provider is deferred (left 'spooled'). Used to
            // slowly rebuild a damaged provider reputation on an engaged seed
            // instead of a hard block. A provider present here is NOT hard-blocked.
            // null/empty => no re-warm policy.
            $table->json('provider_policies')->nullable()->after('blocked_providers');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_sending_domains') && Schema::hasColumn('email_sending_domains', 'provider_policies')) {
            Schema::table('email_sending_domains', function (Blueprint $table) {
                $table->dropColumn('provider_policies');
            });
        }
    }
};
