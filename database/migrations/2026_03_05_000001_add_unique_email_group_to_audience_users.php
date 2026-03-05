<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_users', function (Blueprint $table) {
            // Unique constraint prevents duplicate subscribers within the same group
            // and enables true upsert behavior (DB::table()->upsert())
            $table->unique(['email_audience_group_id', 'email'], 'audience_users_group_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('audience_users', function (Blueprint $table) {
            $table->dropUnique('audience_users_group_email_unique');
        });
    }
};
