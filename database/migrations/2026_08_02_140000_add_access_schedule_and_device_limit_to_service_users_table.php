<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // Time-of-day access window. Days are ISO weekday numbers (1=Mon..
            // 7=Sun); empty/null means every day. The two minute-of-day bounds
            // (0..1439, local time) are null when there is no time restriction.
            // Outside the window the user is auto-suspended and auto-resumed --
            // a kid's device that only works 16:00-21:00, say.
            $table->json('access_days')->nullable()->after('rate_limit_kbps');
            $table->unsignedSmallInteger('access_start_minute')->nullable()->after('access_days');
            $table->unsignedSmallInteger('access_end_minute')->nullable()->after('access_start_minute');

            // OpenVPN only: cap how many devices may use this one config at
            // once. Null means no cap. WireGuard configs are single-device by
            // design, so this does not apply to them.
            $table->unsignedSmallInteger('max_connections')->nullable()->after('access_end_minute');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn(['access_days', 'access_start_minute', 'access_end_minute', 'max_connections']);
        });
    }
};
