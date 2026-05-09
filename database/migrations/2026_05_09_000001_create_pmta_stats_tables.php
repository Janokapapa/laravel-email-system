<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pmta_stats_buckets')) {
            Schema::create('pmta_stats_buckets', function (Blueprint $table) {
                $table->id();
                $table->string('server', 50);
                $table->enum('granularity', ['hour', 'day']);
                $table->timestamp('bucket_start');
                $table->unsignedInteger('delivered')->default(0);
                $table->unsignedInteger('bounced_stop')->default(0);
                $table->unsignedInteger('bounced_go')->default(0);
                $table->json('domains');
                $table->json('ips');
                $table->timestamps();

                $table->unique(['server', 'granularity', 'bucket_start'], 'pmta_buckets_unique');
                $table->index(['server', 'bucket_start']);
                $table->index('bucket_start');
            });
        }

        if (!Schema::hasTable('pmta_stats_snapshots')) {
            Schema::create('pmta_stats_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('server', 50);
                $table->unsignedTinyInteger('period_days');
                $table->timestamp('snapshot_at');
                $table->unsignedInteger('delivered')->default(0);
                $table->unsignedInteger('bounced_stop')->default(0);
                $table->unsignedInteger('bounced_go')->default(0);
                $table->json('domains');
                $table->json('ips');
                $table->timestamp('created_at')->nullable();

                $table->index(['server', 'period_days', 'snapshot_at']);
                $table->index('snapshot_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pmta_stats_snapshots');
        Schema::dropIfExists('pmta_stats_buckets');
    }
};
