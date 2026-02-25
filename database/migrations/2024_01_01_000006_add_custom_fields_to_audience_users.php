<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audience_users') && !Schema::hasColumn('audience_users', 'custom_fields')) {
            Schema::table('audience_users', function (Blueprint $table) {
                $table->json('custom_fields')->nullable()->after('sent_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audience_users', 'custom_fields')) {
            Schema::table('audience_users', function (Blueprint $table) {
                $table->dropColumn('custom_fields');
            });
        }
    }
};
