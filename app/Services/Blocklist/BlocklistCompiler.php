<?php

namespace App\Services\Blocklist;

use Illuminate\Support\Facades\File;

/**
 * Owns the single on-disk hosts file that every opted-in resolver reads via
 * `addn-hosts`. Writing it is separate from fetching the sources (the job) so
 * the resolver only ever depends on one static path, whose contents change.
 */
class BlocklistCompiler
{
    public const DIR = '/etc/vpnforge/blocklists';

    public const FILE = self::DIR.'/blocklist.hosts';

    /**
     * Make sure the file exists (empty is fine) so a resolver that references
     * it via `addn-hosts` never fails to start over a missing file, even
     * before any list has been fetched.
     */
    public function ensureFile(): void
    {
        File::ensureDirectoryExists(self::DIR, 0755);

        if (! File::exists(self::FILE)) {
            File::put(self::FILE, '');
        }
    }

    /**
     * Write the merged domains as a hosts file (`0.0.0.0 domain`). 0.0.0.0 is
     * unroutable, so a blocked name resolves to nothing a client can connect
     * to. Written to a temp file and renamed, so a resolver reading it never
     * sees a half-written file.
     *
     * @param  list<string>  $domains
     */
    public function write(array $domains): void
    {
        File::ensureDirectoryExists(self::DIR, 0755);

        $body = collect($domains)
            ->map(fn (string $domain) => "0.0.0.0 {$domain}")
            ->implode("\n");

        $tmp = self::FILE.'.new';
        File::put($tmp, $body === '' ? '' : $body."\n");
        @chmod($tmp, 0644);
        File::move($tmp, self::FILE);
    }
}
