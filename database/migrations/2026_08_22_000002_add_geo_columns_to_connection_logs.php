<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_logs', function (Blueprint $table) {
            // GeoIP enrichment of peer_ip, captured when a session is first
            // seen. All nullable: the databases are optional, and existing rows
            // predate the feature and stay valid untouched.
            $table->string('country_code', 2)->nullable()->after('peer_ip');
            $table->string('country_name')->nullable()->after('country_code');
            $table->unsignedInteger('asn')->nullable()->after('country_name');
            $table->string('as_org')->nullable()->after('asn');
        });
    }

    public function down(): void
    {
        Schema::table('connection_logs', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'country_name', 'asn', 'as_org']);
        });
    }
};
