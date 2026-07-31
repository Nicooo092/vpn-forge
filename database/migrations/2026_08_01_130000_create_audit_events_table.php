<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Null when a background job made the change rather than a
            // person -- suspending someone whose allowance ran out, say.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('subject')->nullable(); // Name at the time, so a deleted record still reads sensibly.
            $table->string('event'); // created | updated | deleted

            // Only the fields that actually changed, before and after, with
            // key material and rendered configs stripped out.
            // Not named `changes`: Eloquent's HasAttributes already declares
            // a protected $changes for its own dirty tracking, so a column of
            // that name reads back as the model's internal state from inside
            // the class and as the column from outside it.
            $table->json('change_set')->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
