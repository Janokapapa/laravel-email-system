<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pmta_bounce_counters')) {
            Schema::create('pmta_bounce_counters', function (Blueprint $table) {
                $table->id();
                $table->string('server', 50);
                $table->string('bounce_cat', 50);
                $table->timestamp('counter_hour');
                $table->unsignedInteger('count')->default(0);
                $table->timestamps();

                $table->unique(['server', 'bounce_cat', 'counter_hour'], 'pmta_bounce_counters_unique');
                $table->index(['counter_hour'], 'pmta_bounce_counters_hour_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pmta_bounce_counters');
    }
};
