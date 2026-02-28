<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'skip_providers')) {
                $table->json('skip_providers')->nullable()->after('skip_yahoo');
            }
        });

        // Migrate existing data: skip_yahoo=true → ['yahoo']
        \Illuminate\Support\Facades\DB::table('campaigns')
            ->where('skip_yahoo', true)
            ->update(['skip_providers' => json_encode(['yahoo'])]);

        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'skip_yahoo')) {
                $table->dropColumn('skip_yahoo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'skip_yahoo')) {
                $table->boolean('skip_yahoo')->default(false)->after('audience_group_ids');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'skip_providers')) {
                $table->dropColumn('skip_providers');
            }
        });
    }
};
