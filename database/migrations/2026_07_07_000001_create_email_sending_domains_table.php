<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_sending_domains')) {
            return;
        }

        Schema::create('email_sending_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            // Anchors the warmup curve — set on the domain's first ever send.
            $table->timestamp('first_sent_at')->nullable();
            // Per-domain override: disable warmup entirely for this domain.
            $table->boolean('warmup_enabled')->default(true);
            // Per-domain override: raise/lower the warmup ceiling (null = use config).
            $table->unsignedBigInteger('max_daily')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sending_domains');
    }
};
