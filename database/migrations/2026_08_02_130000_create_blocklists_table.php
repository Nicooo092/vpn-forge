<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subscriptions to public blocklists (StevenBlack, oisd, ...). Fetched
        // on a schedule, merged into one hosts file, and served by every
        // service's resolver that opts in -- network-wide ad/malware blocking
        // that maintains itself.
        Schema::create('blocklists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->boolean('enabled')->default(true);

            // Filled in by the refresh job so the panel can show whether a list
            // is live and how big it is.
            $table->unsignedInteger('domain_count')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklists');
    }
};
