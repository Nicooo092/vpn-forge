<?php

namespace Tests\Feature;

use App\Services\Geo\GeoLocator;
use App\Services\Geo\MmdbReader;
use Tests\TestCase;

/**
 * GeoLocator is pure enrichment: it must resolve a public IP against fake
 * country/ASN readers, and -- crucially -- degrade to null on every unhappy
 * path (private/reserved/invalid IP, no record, one or both databases missing)
 * without ever throwing, because the databases are optional.
 */
class GeoLocatorTest extends TestCase
{
    /**
     * @param  array<string, array<string, mixed>>  $map
     */
    private function reader(array $map): MmdbReader
    {
        return new class($map) implements MmdbReader
        {
            /** @param array<string, array<string, mixed>> $map */
            public function __construct(private array $map) {}

            public function get(string $ip): ?array
            {
                return $this->map[$ip] ?? null;
            }
        };
    }

    public function test_it_formats_a_country_and_asn_record(): void
    {
        $geo = new GeoLocator(
            $this->reader(['8.8.8.8' => ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]]]),
            $this->reader(['8.8.8.8' => ['autonomous_system_number' => 15169, 'autonomous_system_organization' => 'Google LLC']]),
        );

        $this->assertSame([
            'country_code' => 'US',
            'country_name' => 'United States',
            'asn' => 15169,
            'as_org' => 'Google LLC',
        ], $geo->locate('8.8.8.8'));
    }

    public function test_it_degrades_when_only_one_database_has_the_record(): void
    {
        $geo = new GeoLocator(
            $this->reader(['8.8.8.8' => ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]]]),
            $this->reader([]),
        );

        $this->assertSame([
            'country_code' => 'US',
            'country_name' => 'United States',
            'asn' => null,
            'as_org' => null,
        ], $geo->locate('8.8.8.8'));
    }

    public function test_it_returns_null_when_neither_database_has_the_record(): void
    {
        $geo = new GeoLocator($this->reader([]), $this->reader([]));

        $this->assertNull($geo->locate('8.8.8.8'));
    }

    public function test_it_returns_null_for_private_and_reserved_addresses(): void
    {
        // Even with a (bogus) match present, a private/reserved IP is never
        // looked up.
        $geo = new GeoLocator(
            $this->reader(['10.0.0.5' => ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]]]),
            $this->reader([]),
        );

        $this->assertNull($geo->locate('10.0.0.5'));
        $this->assertNull($geo->locate('192.168.1.1'));
        $this->assertNull($geo->locate('127.0.0.1'));
        $this->assertNull($geo->locate('169.254.10.10'));
    }

    public function test_it_returns_null_for_an_invalid_or_empty_ip(): void
    {
        $geo = new GeoLocator($this->reader([]), $this->reader([]));

        $this->assertNull($geo->locate(null));
        $this->assertNull($geo->locate(''));
        $this->assertNull($geo->locate('not-an-ip'));
        $this->assertNull($geo->locate('999.1.1.1'));
    }

    public function test_from_config_with_a_missing_database_file_never_throws(): void
    {
        config([
            'vpnforge.geoip.country_db' => '/nonexistent/vpnforge/dbip-country-lite.mmdb',
            'vpnforge.geoip.asn_db' => '/nonexistent/vpnforge/dbip-asn-lite.mmdb',
        ]);

        $geo = GeoLocator::fromConfig();

        // A missing file degrades to null rather than throwing on open.
        $this->assertNull($geo->locate('8.8.8.8'));
    }

    public function test_it_builds_a_flag_emoji_from_an_iso_code_and_ignores_junk(): void
    {
        $this->assertSame("\u{1F1FA}\u{1F1F8}", GeoLocator::flagEmoji('US'));
        $this->assertSame("\u{1F1EB}\u{1F1F7}", GeoLocator::flagEmoji('fr'));
        $this->assertSame('', GeoLocator::flagEmoji(null));
        $this->assertSame('', GeoLocator::flagEmoji('X'));
        $this->assertSame('', GeoLocator::flagEmoji('123'));
    }
}
