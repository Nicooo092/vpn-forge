<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where out-of-band alerts go: a Discord/Telegram/ntfy webhook or an
        // email address, each subscribing to some or all events.
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // discord | telegram | ntfy | email
            $table->json('config'); // transport-specific (webhook url, token, ...)
            $table->json('events')->nullable(); // subscribed event keys; null/empty = all
            $table->boolean('enabled')->default(true);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
