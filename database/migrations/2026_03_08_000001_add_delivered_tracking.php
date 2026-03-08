<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('bounced_at');
                $table->index('delivered_at');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'delivered_count')) {
                $table->unsignedInteger('delivered_count')->default(0)->after('failed_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'delivered_at')) {
                $table->dropIndex(['delivered_at']);
                $table->dropColumn('delivered_at');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'delivered_count')) {
                $table->dropColumn('delivered_count');
            }
        });
    }
};
