<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable(); // null while the session is still live
            $table->ipAddress('peer_ip')->nullable(); // the client's real-world source IP
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamps();

            $table->index(['service_user_id', 'connected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_logs');
    }
};
