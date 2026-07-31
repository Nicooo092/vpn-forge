<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Written by the Go capture agent (its own least-privileged DB user
        // only has INSERT/SELECT here) and read by the panel. Append-only --
        // no updated_at, no Eloquent-managed timestamps.
        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Nullable: kept even when the source IP doesn't resolve to a
            // known user (e.g. right after a peer was removed), so nothing
            // is silently dropped.
            $table->foreignId('service_user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind'); // dns | http
            $table->timestamp('occurred_at');
            $table->ipAddress('source_ip');
            $table->string('host')->nullable();

            // dns: {query_type, answer, ttl}
            // http: {method, path, status_code, headers, body}
            $table->json('detail')->nullable();

            $table->index(['service_id', 'occurred_at']);
            $table->index(['service_user_id', 'occurred_at']);
            $table->index('host');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_logs');
    }
};
