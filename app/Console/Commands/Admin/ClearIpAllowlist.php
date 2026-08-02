<?php

namespace App\Console\Commands\Admin;

use App\Models\AdminIpRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The escape hatch for the admin IP allowlist. If a rule (or a changed home IP)
 * has locked the operator out of the panel, they run this over SSH to wipe the
 * allowlist and make the panel reachable from anywhere again.
 */
#[Signature('vpnforge:ip-allowlist-clear')]
#[Description('Remove every admin IP allowlist rule (fail-safe for a lock-out)')]
class ClearIpAllowlist extends Command
{
    public function handle(): int
    {
        $count = AdminIpRule::query()->count();

        AdminIpRule::query()->delete();

        $this->info($count === 0
            ? 'The allowlist was already empty; the panel is reachable from anywhere.'
            : "Cleared {$count} rule(s). The panel is reachable from anywhere again.");

        return self::SUCCESS;
    }
}
