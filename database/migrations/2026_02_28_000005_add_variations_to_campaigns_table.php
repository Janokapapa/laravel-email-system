<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('campaigns', 'variations')) {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('variations')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('variations');
        });
    }
};
