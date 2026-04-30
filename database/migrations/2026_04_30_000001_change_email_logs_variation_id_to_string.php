<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('email_logs', 'variation_id')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('variation_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('email_logs', 'variation_id')) {
            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('variation_id')->nullable()->change();
        });
    }
};
