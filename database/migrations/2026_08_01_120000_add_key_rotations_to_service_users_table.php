<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // How many times this user's key material has been reissued.
            // OpenVPN's common name is derived from it, because easy-rsa
            // refuses to build a certificate for a common name it has
            // already revoked -- so every reissue needs a name of its own.
            $table->unsignedInteger('key_rotations')->default(0)->after('certificate_serial');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn('key_rotations');
        });
    }
};
