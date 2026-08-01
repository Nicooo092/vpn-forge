<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // Per-user speed cap in kilobits per second, applied symmetrically
            // to download and upload via tc/HTB on the tunnel interface. Null
            // means no limit.
            $table->unsignedInteger('rate_limit_kbps')->nullable()->after('dns_override');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn('rate_limit_kbps');
        });
    }
};
