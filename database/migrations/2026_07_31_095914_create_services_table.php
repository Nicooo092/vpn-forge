<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('protocol'); // wireguard | openvpn
            $table->string('transport'); // udp | tcp
            $table->string('status')->default('provisioning'); // provisioning|active|error|disabled
            $table->string('interface_name')->unique(); // wg0, wg1, ...
            $table->string('subnet_cidr');
            $table->unsignedSmallInteger('listen_port');
            $table->boolean('logging_enabled_default')->default(false);
            $table->json('config')->nullable(); // protocol-specific knobs
            $table->text('last_error')->nullable();
            $table->timestamps();

            // A UDP and a TCP service may share a numeric port; two services on
            // the same transport may not.
            $table->unique(['transport', 'listen_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
