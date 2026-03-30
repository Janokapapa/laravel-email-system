<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('content_type', 10)->default('both')->change();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('content_type', 10)->default('both')->change();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('content_type', 10)->default('both')->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('content_type', 10)->default('html')->change();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('content_type', 10)->default('html')->change();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('content_type', 10)->default('html')->change();
        });
    }
};
