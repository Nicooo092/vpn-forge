<?php

namespace Tests\Feature;

use App\Services\ServiceUsers\CsvUserImporter;
use App\Services\ServiceUsers\CsvUserImportResult;
use Tests\TestCase;

/**
 * The parser is pure -- no database, no jobs -- so its behaviour is pinned here
 * directly: header detection and column mapping, positional fallback, blank
 * lines, per-row validation with reasons, control-char rejection and the row
 * cap. The admin-only write path that turns these rows into users and jobs is a
 * thin wrapper over this and over the already-tested single-add flow.
 */
class CsvUserImporterTest extends TestCase
{
    private function parse(string $csv, ?int $max = null): CsvUserImportResult
    {
        return (new CsvUserImporter)->parse($csv, $max);
    }

    public function test_a_header_row_is_detected_and_values_parsed_into_canonical_units(): void
    {
        $csv = "name,label,rate_limit_kbps,data_limit_gb,expires_at,dns\n"
            ."phone,family,20000,50,2026-12-31,1.1.1.1;9.9.9.9\n";

        $result = $this->parse($csv);

        $this->assertSame(0, $result->skippedCount());
        $this->assertCount(1, $result->valid);

        $row = $result->valid[0];
        $this->assertSame('phone', $row['name']);
        $this->assertSame(['family'], $row['labels']);
        $this->assertSame(20000, $row['rate_limit_kbps']);
        // GB -> bytes.
        $this->assertSame(50 * 1024 ** 3, $row['data_limit_bytes']);
        $this->assertSame(['1.1.1.1', '9.9.9.9'], $row['dns_override']);
        $this->assertSame('2026-12-31', $row['expires_at']->format('Y-m-d'));
    }

    public function test_columns_may_be_reordered_when_a_header_is_present(): void
    {
        $result = $this->parse("dns,name\n1.1.1.1,phone\n");

        $this->assertCount(1, $result->valid);
        $this->assertSame('phone', $result->valid[0]['name']);
        $this->assertSame(['1.1.1.1'], $result->valid[0]['dns_override']);
        // Absent optional columns default to null, not an error.
        $this->assertNull($result->valid[0]['rate_limit_kbps']);
    }

    public function test_a_headerless_file_is_read_in_canonical_column_order(): void
    {
        $result = $this->parse("phone\nlaptop,work\n");

        $this->assertSame(0, $result->skippedCount());
        $this->assertCount(2, $result->valid);
        $this->assertSame('phone', $result->valid[0]['name']);
        $this->assertNull($result->valid[0]['labels']);
        $this->assertSame(['work'], $result->valid[1]['labels']);
    }

    public function test_blank_lines_are_ignored(): void
    {
        $result = $this->parse("name\n\nphone\n\n\nlaptop\n");

        $this->assertCount(2, $result->valid);
        $this->assertSame(0, $result->skippedCount());
    }

    public function test_bad_rows_are_skipped_with_reasons_and_good_rows_kept(): void
    {
        $csv = implode("\n", [
            'name,label,rate_limit_kbps,data_limit_gb,expires_at,dns',
            ',orphan',                // 2: missing name
            'ok',                     // 3: valid
            'bad-dns,,,,,not-an-ip',  // 4: bad dns
            'bad-rate,,abc',          // 5: bad rate
            'bad-quota,,,-5',         // 6: bad data limit
            'bad-date,,,,not-a-date', // 7: bad expiry
        ])."\n";

        $result = $this->parse($csv);

        $this->assertCount(1, $result->valid);
        $this->assertSame('ok', $result->valid[0]['name']);
        $this->assertSame(5, $result->skippedCount());

        // Line numbers count every physical line, header included.
        $reasons = collect($result->skipped)->pluck('reason', 'line');
        $this->assertStringContainsString('name', (string) $reasons[2]);
        $this->assertStringContainsString('IPv4', (string) $reasons[4]);
    }

    public function test_a_control_character_in_the_name_is_rejected(): void
    {
        // A tab (\x09) is a control character, as a newline would be.
        $result = $this->parse("name\nph\tone\n");

        $this->assertCount(0, $result->valid);
        $this->assertSame(1, $result->skippedCount());
    }

    public function test_the_row_cap_stops_reading_and_reports_the_remainder(): void
    {
        $result = $this->parse("a\nb\nc\nd\n", 2);

        $this->assertCount(2, $result->valid);
        // One "cap reached" skip note, not one per unread row.
        $this->assertSame(1, $result->skippedCount());
    }
}
