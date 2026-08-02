<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allowed source networks for the admin panel. An EMPTY table means no
        // restriction (the panel is reachable from anywhere); as soon as one
        // rule exists, only matching addresses get in -- plus loopback, always,
        // so a local/SSH-tunnel operator can never be locked out.
        Schema::create('admin_ip_rules', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->string('cidr'); // an IP or CIDR, v4 or v6
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ip_rules');
    }
};
