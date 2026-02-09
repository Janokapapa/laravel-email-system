<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_logs')) {
            return;
        }

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')
                ->nullable()
                ->constrained('email_templates')
                ->nullOnDelete();
            $table->foreignId('email_audience_group_id')
                ->nullable()
                ->constrained('email_audience_groups')
                ->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('recipient');
            $table->string('subject');
            $table->text('message');
            $table->string('sender')->nullable();
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->string('status')->default('queued');
            $table->boolean('opened')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->boolean('clicked')->default(false);
            $table->timestamp('clicked_at')->nullable();
            $table->text('error')->nullable();
            $table->string('mailgun_message_id')->nullable();
            $table->string('bounce_type')->nullable();
            $table->text('bounce_reason')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->boolean('complained')->default(false);
            $table->timestamp('complained_at')->nullable();
            $table->timestamps();

            $table->index('recipient');
            $table->index('status');
            $table->index('mailgun_message_id');
            $table->index(['email_template_id', 'email_audience_group_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
