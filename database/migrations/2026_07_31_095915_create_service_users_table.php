<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('active'); // active|revoked
            $table->ipAddress('tunnel_ip');

            // WireGuard -- private/preshared keys are stored (encrypted at
            // the Eloquent cast level) so the config file can be
            // re-downloaded later without regenerating keys.
            $table->text('wg_public_key')->nullable();
            $table->text('wg_private_key')->nullable();
            $table->text('wg_preshared_key')->nullable();

            // OpenVPN
            $table->string('openvpn_common_name')->nullable();
            $table->string('certificate_serial')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // null = inherit the service's logging_enabled_default
            $table->boolean('logging_override')->nullable();

            // Denormalized for cheap list rendering
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->ipAddress('last_seen_ip')->nullable();

            // The last cumulative byte counters seen from the driver's
            // pollStatus(), so the poller can compute a bandwidth_samples
            // delta without an extra query per user on every poll.
            $table->unsignedBigInteger('last_cumulative_bytes_in')->default(0);
            $table->unsignedBigInteger('last_cumulative_bytes_out')->default(0);

            $table->timestamps();

            $table->unique(['service_id', 'tunnel_ip']);
            $table->index(['service_id', 'tunnel_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_users');
    }
};
