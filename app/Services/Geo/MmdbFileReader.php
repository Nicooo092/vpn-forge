<?php

namespace App\Services\Geo;

use MaxMind\Db\Reader;
use Throwable;

/**
 * Reads a MaxMind-format .mmdb file (DB-IP Lite ships the same format). Opening
 * is lazy and failure-tolerant by design: a missing, unreadable or corrupt file
 * yields a reader that simply returns null for every lookup, so the geo feature
 * switches itself off rather than throwing. The database is optional.
 */
class MmdbFileReader implements MmdbReader
{
    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly ?string $path) {}

    public function get(string $ip): ?array
    {
        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            /** @var array<string, mixed>|null $record */
            $record = $reader->get($ip);

            return $record;
        } catch (Throwable) {
            // e.g. an address type the database does not index -- treat as
            // "unknown", never as an error.
            return null;
        }
    }

    private function reader(): ?Reader
    {
        if ($this->opened) {
            return $this->reader;
        }

        $this->opened = true;

        if ($this->path === null || $this->path === '' || ! is_file($this->path)) {
            return null;
        }

        try {
            $this->reader = new Reader($this->path);
        } catch (Throwable) {
            $this->reader = null;
        }

        return $this->reader;
    }
}
