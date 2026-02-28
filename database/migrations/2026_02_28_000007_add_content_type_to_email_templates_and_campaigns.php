<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('email_templates', 'content_type')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->string('content_type', 10)->default('html')->after('name');
            });
        }

        if (!Schema::hasColumn('campaigns', 'content_type')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->string('content_type', 10)->default('html')->after('email_template_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });
    }
};
