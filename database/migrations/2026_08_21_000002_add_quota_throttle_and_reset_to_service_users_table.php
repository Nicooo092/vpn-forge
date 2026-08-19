<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // What happens when the allowance is used up: cut the user off (the
            // original behaviour) or slow them to a low speed. Defaulted to
            // 'suspend' so existing users keep behaving exactly as before.
            $table->string('quota_action')->default('suspend')->after('quota_started_at');

            // The speed (kbit/s) a throttled user is capped to, applied via
            // tc/HTB just like rate_limit_kbps. Null until a throttle is set.
            $table->unsignedInteger('quota_throttle_kbps')->nullable()->after('quota_action');

            // Whether this user is currently throttled for exhausting their
            // allowance -- the state EnforceUserLimits sets and clears, and that
            // TrafficShaper reads to know which rate wins.
            $table->boolean('quota_throttled')->default(false)->after('quota_throttle_kbps');

            // Cadence the allowance window resets on its own: none | weekly |
            // monthly. Defaulted to 'none' so nothing resets unless asked for.
            $table->string('quota_reset')->default('none')->after('quota_throttled');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn([
                'quota_action',
                'quota_throttle_kbps',
                'quota_throttled',
                'quota_reset',
            ]);
        });
    }
};
