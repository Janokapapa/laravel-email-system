<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->boolean('unsubscribed')->default(false)->after('clicked_at');
            $table->timestamp('unsubscribed_at')->nullable()->after('unsubscribed');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn(['unsubscribed', 'unsubscribed_at']);
        });
    }
};
