<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tiny key/value store for singleton panel state that has to outlive
        // the cache. The lockdown flag lives here because it is a security
        // control: it must survive a cache:clear, a queue restart and a reboot,
        // and be the same authoritative value for every process -- none of
        // which the cache guarantees.
        Schema::create('settings', function (Blueprint $table) {
            // Addressed by name, not by an auto id.
            $table->string('key')->primary();
            // JSON so a setting can carry structured state (the lockdown row
            // records who engaged it and when), not just a scalar. Nullable so
            // a key can exist as a bare flag.
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
