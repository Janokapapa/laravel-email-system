<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bounced_emails')) {
            Schema::create('bounced_emails', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('bounce_type', 50)->default('hard');
                $table->text('bounce_reason')->nullable();
                $table->string('source', 50)->default('pmta');
                $table->timestamp('bounced_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bounced_emails');
    }
};
