<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named bundles of default per-user settings an operator applies when
        // creating a service user ("child", "guest", "work"). Purely a create-
        // form convenience -- nothing here is read at enforcement time, and no
        // service_user row ever references one; applying a template copies its
        // values into the new user and the two then live independent lives.
        Schema::create('service_user_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Every field mirrors the matching service_users column exactly --
            // same canonical units (kbit/s, bytes, minute-of-day, ISO weekday
            // numbers), same nullable-means-unset semantics -- so applying one
            // is a straight copy. Null on any field means "leave the form's own
            // default", not "force empty".
            $table->boolean('logging_override')->nullable();
            $table->json('labels')->nullable();
            $table->json('dns_override')->nullable();
            $table->unsignedInteger('rate_limit_kbps')->nullable();
            $table->unsignedBigInteger('data_limit_bytes')->nullable();
            $table->json('access_days')->nullable();
            $table->unsignedSmallInteger('access_start_minute')->nullable();
            $table->unsignedSmallInteger('access_end_minute')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_user_templates');
    }
};
