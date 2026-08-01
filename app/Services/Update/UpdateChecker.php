<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Tells the operator whether a newer version has been published, and nothing
 * more. Deliberately read-only: it never pulls, installs or restarts anything
 * -- the actual upgrade is a command the operator runs over SSH (see
 * upgradeCommand()), so the web process is never handed a way to redeploy
 * itself, which would undo the privilege separation the rest of the box is
 * built on.
 */
class UpdateChecker
{
    /**
     * The commit this install is running, short form, or null if the source
     * tree is not a git checkout (e.g. deployed some other way).
     */
    public function currentCommit(): ?string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    private function currentCommitFull(): ?string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * owner/repo, parsed from the checkout's origin remote so a fork reports
     * against itself, with a config override for unusual setups.
     */
    public function repoSlug(): ?string
    {
        if ($configured = config('vpnforge.update.repo')) {
            return $configured;
        }

        $result = Process::path(base_path())->run(['git', 'config', '--get', 'remote.origin.url']);

        if (! $result->successful()) {
            return null;
        }

        if (preg_match('#github\.com[:/]([^/]+/[^/.]+)#', trim($result->output()), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function branch(): string
    {
        return config('vpnforge.update.branch', 'main');
    }

    /**
     * @return array{status: string, current: ?string, behind: int, commits: array<int, string>, checked_at: ?int, message: ?string}
     */
    public function check(bool $fresh = false): array
    {
        $current = $this->currentCommitFull();
        $slug = $this->repoSlug();

        if ($current === null || $slug === null) {
            return $this->result('unknown', null, message: 'This install is not a recognisable git checkout, so updates cannot be checked automatically.');
        }

        $cacheKey = "vpnforge:update:{$current}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHour(), function () use ($slug, $current) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'vpn-forge',
                    'Accept' => 'application/vnd.github+json',
                ])->timeout(8)->get("https://api.github.com/repos/{$slug}/compare/{$current}...{$this->branch()}");
            } catch (\Throwable $e) {
                return $this->result('unknown', $this->currentCommit(), message: 'Could not reach GitHub to check for updates.');
            }

            if (! $response->successful()) {
                return $this->result('unknown', $this->currentCommit(), message: 'GitHub did not answer the update check (HTTP '.$response->status().').');
            }

            $body = $response->json();
            $status = $body['status'] ?? 'unknown';
            $aheadBy = (int) ($body['ahead_by'] ?? 0);

            // base=current, head=branch: "ahead_by" is how many commits the
            // branch is ahead of us -- i.e. how far behind we are.
            if ($status === 'identical' || $aheadBy === 0) {
                return $this->result('up-to-date', $this->currentCommit());
            }

            $messages = collect($body['commits'] ?? [])
                ->reverse() // newest first
                ->map(fn ($c) => strtok((string) ($c['commit']['message'] ?? ''), "\n"))
                ->filter()
                ->take(10)
                ->values()
                ->all();

            return $this->result('behind', $this->currentCommit(), behind: $aheadBy, commits: $messages);
        });
    }

    /**
     * The exact command an operator pastes into an SSH session to upgrade. Kept
     * here so the one canonical deploy sequence lives in one place and the UI
     * only displays it.
     */
    public function upgradeCommand(): string
    {
        $dir = base_path();

        return implode(" && \\\n", [
            "cd {$dir}",
            'sudo -u www-data git pull --ff-only',
            'sudo -u www-data env COMPOSER_HOME=/tmp/composer HOME=/tmp/composer composer install --no-dev --optimize-autoloader --no-interaction',
            'sudo -u www-data php artisan migrate --force',
            'sudo systemctl restart vpnforge-worker',
            'sudo systemctl reload php8.3-fpm',
        ]);
    }

    /**
     * @param  array<int, string>  $commits
     * @return array{status: string, current: ?string, behind: int, commits: array<int, string>, checked_at: ?int, message: ?string}
     */
    private function result(string $status, ?string $current, int $behind = 0, array $commits = [], ?string $message = null): array
    {
        return [
            'status' => $status,
            'current' => $current,
            'behind' => $behind,
            'commits' => $commits,
            'checked_at' => now()->getTimestamp(),
            'message' => $message,
        ];
    }
}
