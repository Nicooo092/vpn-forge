<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // Resolver addresses this one user's device should use instead of
            // the service's own resolver. Null/empty means it inherits the
            // service default (which routes through this panel's dnsmasq so
            // the lookups are logged). Set, the user's queries go straight to
            // these servers -- on WireGuard that replaces the panel resolver
            // entirely, so their lookups stop appearing in the traffic log.
            $table->json('dns_override')->nullable()->after('labels');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn('dns_override');
        });
    }
};
