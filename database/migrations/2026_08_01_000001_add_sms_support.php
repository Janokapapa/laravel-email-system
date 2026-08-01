<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS as a second campaign channel.
 *
 * The campaign, audience and log tables gain a channel rather than getting SMS
 * twins: everything around them (filters, scheduling, progress, reporting) is
 * identical for both channels, and a parallel set of tables would mean keeping
 * two copies of all of it in step.
 *
 * `sms_suppressions` is deliberately its OWN table and not a flag on
 * audience_users. Audiences here are CSV imports that get re-imported and
 * replaced; a flag on the row would be wiped by the next import and the person
 * who sent STOP would be messaged again. An opt-out has to outlive the list it
 * arrived on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'channel')) {
                $table->string('channel', 16)->default('email')->after('status')
                    ->comment('email or sms; fixed once the campaign exists');
                $table->index('channel');
            }
        });

        Schema::table('audience_users', function (Blueprint $table) {
            if (!Schema::hasColumn('audience_users', 'phone')) {
                // E.164 with the plus, so 16 is the theoretical max; 24 leaves room
                // for whatever a CSV brings in before normalisation.
                $table->string('phone', 24)->nullable()->after('email');
                $table->index('phone');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'channel')) {
                $table->string('channel', 16)->default('email')->after('status')
                    ->comment('email or sms; the log is shared by both channels');
                $table->index('channel');
            }
            if (!Schema::hasColumn('email_logs', 'segments')) {
                // Recorded per message because it is what the provider bills, and
                // because the campaign total has to be checkable against the invoice.
                $table->unsignedSmallInteger('segments')->nullable()->after('channel');
            }
        });

        if (!Schema::hasTable('sms_suppressions')) {
            Schema::create('sms_suppressions', function (Blueprint $table) {
                $table->id();
                // Unique: the same number arriving on three imported lists is one
                // person who opted out once.
                $table->string('phone', 24)->unique();
                $table->string('reason', 32)->default('stop');
                $table->string('source', 64)->nullable()->comment('where the opt-out came from');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_suppressions');

        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'segments')) {
                $table->dropColumn('segments');
            }
            if (Schema::hasColumn('email_logs', 'channel')) {
                $table->dropIndex(['channel']);
                $table->dropColumn('channel');
            }
        });

        Schema::table('audience_users', function (Blueprint $table) {
            if (Schema::hasColumn('audience_users', 'phone')) {
                $table->dropIndex(['phone']);
                $table->dropColumn('phone');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'channel')) {
                $table->dropIndex(['channel']);
                $table->dropColumn('channel');
            }
        });
    }
};
