<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'campaign_id')) {
                $table->foreignId('campaign_id')
                    ->nullable()
                    ->after('email_audience_group_id')
                    ->constrained('campaigns')
                    ->nullOnDelete();

                $table->index('campaign_id');
            }

            // Make message column nullable so campaign emails can reference
            // campaign.body instead of duplicating HTML per row (future optimization)
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'campaign_id')) {
                $table->dropForeign(['campaign_id']);
                $table->dropIndex(['campaign_id']);
                $table->dropColumn('campaign_id');
            }

            $table->text('message')->nullable(false)->change();
        });
    }
};
