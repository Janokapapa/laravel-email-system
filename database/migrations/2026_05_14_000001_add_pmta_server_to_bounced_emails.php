<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bounced_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('bounced_emails', 'pmta_server')) {
                $table->string('pmta_server', 50)->nullable()->after('source')->index();
            }
            if (!Schema::hasColumn('bounced_emails', 'source_domain')) {
                $table->string('source_domain', 100)->nullable()->after('pmta_server');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bounced_emails', function (Blueprint $table) {
            if (Schema::hasColumn('bounced_emails', 'pmta_server')) {
                $table->dropIndex(['pmta_server']);
                $table->dropColumn('pmta_server');
            }
            if (Schema::hasColumn('bounced_emails', 'source_domain')) {
                $table->dropColumn('source_domain');
            }
        });
    }
};
