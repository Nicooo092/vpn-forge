<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A first-class "was this lookup answered locally (blocked) rather than
 * forwarded?" flag on each DNS row, written by the Go capture agent.
 *
 * The blocking dashboard's central figure is blocked-vs-allowed over a window.
 * Deriving that from the JSON detail column is both unindexable and unreliable
 * on MariaDB (JSON_EXTRACT returns the string 'true', which does not compare
 * equal to the SQL boolean), so it lives in its own nullable column. Nullable
 * and left unset so every existing row -- and every HTTP row, where the flag
 * does not apply -- stays valid and simply reads as "not known blocked".
 *
 * The composite index leads with the two equality columns the rollup pins
 * (kind, blocked) and carries occurred_at last, so the window bound is a range
 * seek on the tail and the blocked count never touches a non-blocked row. The
 * total-over-window count is already served by traffic_logs_kind_occurred_host_index,
 * and the per-service counts by the existing (service_id, occurred_at) composite,
 * so no other index is added to this insert-heavy table. Guarded with hasIndex()
 * so it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_logs', function (Blueprint $table) {
            $table->boolean('blocked')->nullable()->after('host');

            if (! Schema::hasIndex('traffic_logs', 'traffic_logs_kind_blocked_occurred_index')) {
                $table->index(['kind', 'blocked', 'occurred_at'], 'traffic_logs_kind_blocked_occurred_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('traffic_logs', function (Blueprint $table) {
            if (Schema::hasIndex('traffic_logs', 'traffic_logs_kind_blocked_occurred_index')) {
                $table->dropIndex('traffic_logs_kind_blocked_occurred_index');
            }

            $table->dropColumn('blocked');
        });
    }
};
