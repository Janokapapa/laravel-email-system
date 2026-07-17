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
        if (Schema::hasColumn('email_sending_domains', 'warmup_base')) {
            return;
        }

        Schema::table('email_sending_domains', function (Blueprint $table) {
            // Per-domain warmup base override (accelerated warmup). The curve is
            // daily_cap = base * (factor ** day_index); this raises the STARTING
            // cap for one domain without touching the global base (default 50).
            // Use for a brand-new From/DKIM domain whose sending IPs are already
            // warm (only the domain reputation is cold) so it can ramp in days,
            // not weeks: e.g. warmup_base=1000 → 1000/2000/4000/8000/16000.
            // null => use the global warmup base.
            $table->unsignedInteger('warmup_base')->nullable()->after('warmup_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_sending_domains') && Schema::hasColumn('email_sending_domains', 'warmup_base')) {
            Schema::table('email_sending_domains', function (Blueprint $table) {
                $table->dropColumn('warmup_base');
            });
        }
    }
};
