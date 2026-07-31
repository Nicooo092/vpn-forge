<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // The full downloadable client config (filename + contents,
            // JSON-encoded, encrypted at the Eloquent cast level), rendered
            // by the privileged worker at addUser()/applyServiceConfig()
            // time. Filament's "download" action reads this column instead
            // of calling the driver directly -- the web process (www-data)
            // has no filesystem access to the private keys/certificates
            // under /etc/wireguard or /etc/openvpn/vpnforge, only the
            // worker does.
            $table->text('rendered_client_config')->nullable()->after('wg_preshared_key');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn('rendered_client_config');
        });
    }
};
