<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the query shapes the dashboard and the poller re-run constantly,
 * and one redundant index removed.
 *
 * Three real problems, all measured against what the code actually asks for:
 *
 * 1. `ConnectionLog::whereNull('disconnected_at')->count()` (the "connected now"
 *    figure) had no index to use and scanned the whole connection_logs table --
 *    a table that only grows -- on every Server health poll. The composite here
 *    leads with disconnected_at so that count is an index range, and carries
 *    (service_user_id, connected_at) so the poller's per-user open-session
 *    lookup is served by the same index instead of needing its own.
 *
 * 2. TopDomains groups DNS rows by host over a 24h window with no service bound,
 *    so neither existing (service_id|service_user_id, occurred_at) composite was
 *    usable and `index('kind')` is worthless at two distinct values. The planner
 *    fell back to scanning a large slice of the DNS log on every dashboard load.
 *
 * 3. The per-user allowance SUM read bytes_in_delta/bytes_out_delta, which the
 *    (service_user_id, sampled_at) index does not carry, so every matching row
 *    cost a primary-key lookup. Appending the two delta columns makes it an
 *    index-only scan. Order is deliberate: equality column first, range column
 *    immediately after it, payload last -- moving a delta before sampled_at
 *    would destroy the range seek.
 *
 * Every change is guarded with hasIndex(), so this is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('connection_logs', 'connection_logs_open_sessions_index')) {
                $table->index(
                    ['disconnected_at', 'service_user_id', 'connected_at'],
                    'connection_logs_open_sessions_index'
                );
            }
        });

        Schema::table('traffic_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('traffic_logs', 'traffic_logs_kind_occurred_host_index')) {
                $table->index(['kind', 'occurred_at', 'host'], 'traffic_logs_kind_occurred_host_index');
            }
        });

        Schema::table('bandwidth_samples', function (Blueprint $table) {
            if (! Schema::hasIndex('bandwidth_samples', 'bandwidth_samples_user_window_covering_index')) {
                $table->index(
                    ['service_user_id', 'sampled_at', 'bytes_in_delta', 'bytes_out_delta'],
                    'bandwidth_samples_user_window_covering_index'
                );
            }

            // Superseded by the covering index above, which has the same two
            // leading columns.
            if (Schema::hasIndex('bandwidth_samples', 'bandwidth_samples_service_user_id_sampled_at_index')) {
                $table->dropIndex('bandwidth_samples_service_user_id_sampled_at_index');
            }
        });

        Schema::table('service_users', function (Blueprint $table) {
            if (! Schema::hasIndex('service_users', 'service_users_status_index')) {
                $table->index('status');
            }

            // unique(['service_id', 'tunnel_ip']) already provides an index on
            // exactly these columns in this order, so the separate non-unique
            // one is pure write and disk overhead.
            if (Schema::hasIndex('service_users', 'service_users_service_id_tunnel_ip_index')) {
                $table->dropIndex('service_users_service_id_tunnel_ip_index');
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasIndex('services', 'services_status_index')) {
                $table->index('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('connection_logs', function (Blueprint $table) {
            if (Schema::hasIndex('connection_logs', 'connection_logs_open_sessions_index')) {
                $table->dropIndex('connection_logs_open_sessions_index');
            }
        });

        Schema::table('traffic_logs', function (Blueprint $table) {
            if (Schema::hasIndex('traffic_logs', 'traffic_logs_kind_occurred_host_index')) {
                $table->dropIndex('traffic_logs_kind_occurred_host_index');
            }
        });

        Schema::table('bandwidth_samples', function (Blueprint $table) {
            if (! Schema::hasIndex('bandwidth_samples', 'bandwidth_samples_service_user_id_sampled_at_index')) {
                $table->index(['service_user_id', 'sampled_at']);
            }

            if (Schema::hasIndex('bandwidth_samples', 'bandwidth_samples_user_window_covering_index')) {
                $table->dropIndex('bandwidth_samples_user_window_covering_index');
            }
        });

        Schema::table('service_users', function (Blueprint $table) {
            if (Schema::hasIndex('service_users', 'service_users_status_index')) {
                $table->dropIndex('service_users_status_index');
            }

            if (! Schema::hasIndex('service_users', 'service_users_service_id_tunnel_ip_index')) {
                $table->index(['service_id', 'tunnel_ip']);
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasIndex('services', 'services_status_index')) {
                $table->dropIndex('services_status_index');
            }
        });
    }
};
