<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->foreignId('email_audience_group_id')
                ->constrained('email_audience_groups')
                ->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->boolean('bounced')->default(false);
            $table->string('bounce_type')->nullable();
            $table->text('bounce_reason')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('unsubscribe_token', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('is_active');
            $table->index('bounced');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_users');
    }
};
