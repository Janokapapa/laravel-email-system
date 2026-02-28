<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_template_variations')) {
            Schema::create('email_template_variations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
                $table->string('subject');
                $table->longText('body');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_variations');
    }
};
