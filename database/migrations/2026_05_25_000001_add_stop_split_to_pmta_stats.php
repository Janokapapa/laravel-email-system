<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pmta_stats_buckets', function (Blueprint $table) {
            if (!Schema::hasColumn('pmta_stats_buckets', 'bounced_stop_hard')) {
                $table->unsignedInteger('bounced_stop_hard')->default(0)->after('bounced_stop');
            }
            if (!Schema::hasColumn('pmta_stats_buckets', 'bounced_stop_queue')) {
                $table->unsignedInteger('bounced_stop_queue')->default(0)->after('bounced_stop_hard');
            }
        });

        Schema::table('pmta_stats_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('pmta_stats_snapshots', 'bounced_stop_hard')) {
                $table->unsignedInteger('bounced_stop_hard')->default(0)->after('bounced_stop');
            }
            if (!Schema::hasColumn('pmta_stats_snapshots', 'bounced_stop_queue')) {
                $table->unsignedInteger('bounced_stop_queue')->default(0)->after('bounced_stop_hard');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pmta_stats_buckets', function (Blueprint $table) {
            $table->dropColumn(['bounced_stop_hard', 'bounced_stop_queue']);
        });
        Schema::table('pmta_stats_snapshots', function (Blueprint $table) {
            $table->dropColumn(['bounced_stop_hard', 'bounced_stop_queue']);
        });
    }
};
