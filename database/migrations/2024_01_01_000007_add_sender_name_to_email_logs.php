<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('email_logs', 'sender_name')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->string('sender_name')->nullable()->after('sender');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_logs', 'sender_name')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropColumn('sender_name');
            });
        }
    }
};
