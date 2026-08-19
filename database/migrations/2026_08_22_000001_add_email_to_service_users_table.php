<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // Optional contact address for the user themselves. When set (and a
            // real mailer is configured) they are emailed about their own
            // account -- an approaching expiry, a connection from a new network.
            // Nullable and defaulted-null so every existing row stays valid and
            // opted out. Not a secret, so no encrypted cast.
            $table->string('email')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
