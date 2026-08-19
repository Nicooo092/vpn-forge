<?php

namespace App\Services\Geo;

/**
 * Resolves a source IP to its country and ASN from the local DB-IP Lite
 * databases. Read-only enrichment only. Every unhappy path degrades to null and
 * nothing here ever throws -- a private/reserved/invalid address, an IP the
 * databases do not cover, or a missing database file all yield null -- so a
 * connection log renders identically whether or not the optional databases are
 * installed.
 */
class GeoLocator
{
    public function __construct(
        private readonly MmdbReader $countryReader,
        private readonly MmdbReader $asnReader,
    ) {}

    /**
     * Build a locator from the configured database paths. Bound as a singleton
     * in AppServiceProvider so each mmdb file is opened at most once per process
     * and reused across every row of a table.
     */
    public static function fromConfig(): self
    {
        return new self(
            new MmdbFileReader(config('vpnforge.geoip.country_db')),
            new MmdbFileReader(config('vpnforge.geoip.asn_db')),
        );
    }

    /**
     * @return array{country_code: ?string, country_name: ?string, asn: ?int, as_org: ?string}|null
     */
    public function locate(?string $ip): ?array
    {
        if (! self::isPublicIp($ip)) {
            return null;
        }

        $country = $this->countryReader->get($ip);
        $asn = $this->asnReader->get($ip);

        $countryCode = is_array($country) ? ($country['country']['iso_code'] ?? null) : null;
        $countryName = is_array($country) ? ($country['country']['names']['en'] ?? null) : null;
        $asnNumber = is_array($asn) ? ($asn['autonomous_system_number'] ?? null) : null;
        $asOrg = is_array($asn) ? ($asn['autonomous_system_organization'] ?? null) : null;

        $result = [
            'country_code' => is_string($countryCode) && $countryCode !== '' ? strtoupper($countryCode) : null,
            'country_name' => is_string($countryName) && $countryName !== '' ? $countryName : null,
            'asn' => is_int($asnNumber) ? $asnNumber : (is_numeric($asnNumber) ? (int) $asnNumber : null),
            'as_org' => is_string($asOrg) && $asOrg !== '' ? $asOrg : null,
        ];

        // Nothing resolved at all -- report a clean null rather than a record of
        // four nulls, so callers can test the return value directly.
        if ($result['country_code'] === null
            && $result['country_name'] === null
            && $result['asn'] === null
            && $result['as_org'] === null) {
            return null;
        }

        return $result;
    }

    /**
     * A regional-indicator flag emoji for a two-letter ISO country code, or an
     * empty string for anything that is not one. mb_chr (mbstring) rather than
     * IntlChar so it works without the intl extension.
     */
    public static function flagEmoji(?string $code): string
    {
        if (! is_string($code) || strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '';
        }

        $code = strtoupper($code);

        return mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8')
            .mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
    }

    /**
     * Public, routable addresses only: private (RFC 1918) and reserved ranges
     * (loopback, link-local, 0.0.0.0/8, ...) are never worth a database lookup
     * and never resolve to anything meaningful. IPv6 is validated too.
     */
    public static function isPublicIp(?string $ip): bool
    {
        if (! is_string($ip) || $ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
