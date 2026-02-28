<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_users', function (Blueprint $table) {
            if (!Schema::hasColumn('audience_users', 'zerobounce_status')) {
                $table->string('zerobounce_status')->default('unverified')->after('bounced_at');
                $table->string('zerobounce_sub_status')->nullable()->after('zerobounce_status');
                $table->timestamp('zerobounce_checked_at')->nullable()->after('zerobounce_sub_status');
                $table->index('zerobounce_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audience_users', function (Blueprint $table) {
            if (Schema::hasColumn('audience_users', 'zerobounce_status')) {
                $table->dropIndex(['zerobounce_status']);
                $table->dropColumn(['zerobounce_status', 'zerobounce_sub_status', 'zerobounce_checked_at']);
            }
        });
    }
};
