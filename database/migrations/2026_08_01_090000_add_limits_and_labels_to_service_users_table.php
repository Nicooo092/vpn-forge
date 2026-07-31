<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            // Access ends here on its own. Null means no end date.
            $table->timestamp('expires_at')->nullable()->after('status');

            // Traffic allowance for the current window, in bytes. Null means
            // unlimited. Usage is summed from bandwidth_samples rather than
            // stored, because the interface counters reset whenever the
            // tunnel is brought back up and would lose the total.
            $table->unsignedBigInteger('data_limit_bytes')->nullable()->after('expires_at');

            // Start of the window the allowance is measured over. Reset moves
            // it forward instead of deleting the samples, so history survives.
            $table->timestamp('quota_started_at')->nullable()->after('data_limit_bytes');

            // Why this user was suspended, so the panel can say so and so a
            // re-enable knows whether to reset the quota window too.
            $table->string('suspended_reason')->nullable()->after('quota_started_at');

            $table->json('labels')->nullable()->after('suspended_reason');
        });
    }

    public function down(): void
    {
        Schema::table('service_users', function (Blueprint $table) {
            $table->dropColumn([
                'expires_at',
                'data_limit_bytes',
                'quota_started_at',
                'suspended_reason',
                'labels',
            ]);
        });
    }
};
