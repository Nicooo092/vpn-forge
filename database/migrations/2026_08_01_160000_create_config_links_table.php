<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single-use (or few-use, time-boxed) public link that hands one
        // user their generated client config without the admin having to email
        // key material around. The plaintext token lives only in the URL shown
        // once at creation; only its SHA-256 hash is stored, so a leaked
        // database or backup cannot be turned back into working links.
        Schema::create('config_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_user_id')->constrained()->cascadeOnDelete();

            // Hash of the URL token, not the token itself. Unique + indexed
            // because every public request looks a link up by it.
            $table->string('token_hash', 64)->unique();

            // Null means it never expires on its own (still bounded by uses).
            $table->timestamp('expires_at')->nullable();

            // How many times the config may be revealed before the link dies.
            // One by default -- the whole point is a hand-off you can send and
            // forget.
            $table->unsignedInteger('max_downloads')->default(1);
            $table->unsignedInteger('downloads')->default(0);

            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            // Set when an admin kills the link early, before it expired or was
            // used up.
            $table->timestamp('revoked_at')->nullable();

            // Which admin created it. Null-on-delete: keep the link working if
            // the admin account is later removed.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_links');
    }
};
