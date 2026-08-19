<?php

namespace App\Services\Geo;

/**
 * The narrow slice of a MaxMind-format .mmdb reader that GeoLocator needs:
 * look up one address, get its record array or null. Keeping this behind an
 * interface is what lets the tests hand GeoLocator a fake reader instead of a
 * real database file.
 */
interface MmdbReader
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $ip): ?array;
}
