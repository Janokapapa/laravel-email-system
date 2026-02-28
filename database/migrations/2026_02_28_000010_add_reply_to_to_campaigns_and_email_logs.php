<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('reply_to')->nullable()->after('sender_display_name');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('reply_to')->nullable()->after('sender_display_name');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('reply_to');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn('reply_to');
        });
    }
};
