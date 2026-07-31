<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bandwidth_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Nullable: a null service_user_id row is a service-level total
            // sample, used for the aggregated dashboard chart.
            $table->foreignId('service_user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamp('sampled_at');
            $table->unsignedBigInteger('bytes_in_delta')->default(0);
            $table->unsignedBigInteger('bytes_out_delta')->default(0);

            $table->index(['service_id', 'sampled_at']);
            $table->index(['service_user_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_samples');
    }
};
