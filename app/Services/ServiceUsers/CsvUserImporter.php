<?php

namespace App\Services\ServiceUsers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns the raw bytes of an uploaded CSV into validated, canonical-unit rows
 * ready to become service users -- and a list of the rows it refused, each with
 * a human line number and reason. Pure: it reads no database and creates
 * nothing, so the whole of the parsing and validation can be asserted directly
 * in a test. The caller (the admin-only "Import CSV" action) is what assigns a
 * free tunnel address, writes the rows and dispatches the provisioning jobs.
 *
 * Nothing parsed here is ever handed to a shell or written into a device/server
 * config as-is: these values only ever become database columns and job
 * arguments. The one field with a hard format rule is the name, which the
 * WireGuard/OpenVPN drivers render into a root-loaded config -- so control
 * characters (newlines above all) are rejected here, exactly as the single-add
 * form's not_regex rule rejects them, and again at the driver sink.
 */
class CsvUserImporter
{
    /**
     * The canonical column order assumed when the file has no header row.
     *
     * @var list<string>
     */
    public const COLUMNS = ['name', 'label', 'rate_limit_kbps', 'data_limit_gb', 'expires_at', 'dns'];

    /**
     * @param  string  $contents  the raw CSV bytes
     * @param  int|null  $maxRows  stop after this many data rows (null = no cap)
     */
    public function parse(string $contents, ?int $maxRows = null): CsvUserImportResult
    {
        $valid = [];
        $skipped = [];

        // Strip a UTF-8 BOM a spreadsheet may have prepended, or the first
        // header cell would read "\u{feff}name" and never match.
        $contents = preg_replace('/^\x{FEFF}/u', '', $contents) ?? $contents;

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        $columnMap = null; // column => field index, from a header or positions
        $processed = 0;

        foreach ($lines as $offset => $raw) {
            $line = $offset + 1; // 1-based, counting every physical line

            if (trim($raw) === '') {
                continue; // blank lines are silently skipped, not an error
            }

            $fields = array_map('trim', str_getcsv($raw, ',', '"', '\\'));

            // The first non-blank line either names the columns or is data in
            // canonical order. A header is detected by a literal "name" cell --
            // a real user's name row does not carry the word.
            if ($columnMap === null) {
                if (self::looksLikeHeader($fields)) {
                    $columnMap = self::mapFromHeader($fields);

                    continue; // consumed the header; do not import it as a user
                }

                $columnMap = self::mapFromPositions();
            }

            if ($maxRows !== null && $processed >= $maxRows) {
                $skipped[] = [
                    'line' => $line,
                    'reason' => __('import limit of :max rows reached; the rest of the file was not read', ['max' => $maxRows]),
                ];

                break;
            }

            $processed++;

            try {
                $valid[] = $this->parseRow($fields, $columnMap);
            } catch (RowRejected $e) {
                $skipped[] = ['line' => $line, 'reason' => $e->getMessage()];
            }
        }

        return new CsvUserImportResult($valid, $skipped);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function parseRow(array $fields, array $map): array
    {
        $get = function (string $column) use ($fields, $map): string {
            if (! array_key_exists($column, $map)) {
                return '';
            }

            return trim($fields[$map[$column]] ?? '');
        };

        $name = $get('name');

        if ($name === '') {
            throw new RowRejected(__('missing name'));
        }

        // Same rule the single-add form enforces (not_regex:/[\x00-\x1f\x7f]/):
        // the name is rendered into a root-loaded config.
        if (preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RowRejected(__('name contains control characters'));
        }

        if (mb_strlen($name) > 255) {
            throw new RowRejected(__('name is longer than 255 characters'));
        }

        return [
            'name' => $name,
            'labels' => $this->parseLabels($get('label')),
            'rate_limit_kbps' => $this->parseRateLimit($get('rate_limit_kbps')),
            'data_limit_bytes' => $this->parseDataLimit($get('data_limit_gb')),
            'expires_at' => $this->parseExpiry($get('expires_at')),
            'dns_override' => $this->parseDns($get('dns')),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function parseLabels(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        // Comma is the CSV delimiter, so several labels in one cell are split on
        // ; or | (a quoted "a;b" cell survives str_getcsv as one field).
        $labels = array_values(array_filter(
            array_map('trim', preg_split('/[;|]/', $value) ?: []),
            fn (string $label): bool => $label !== '',
        ));

        return $labels === [] ? null : $labels;
    }

    private function parseRateLimit(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RowRejected(__('rate limit must be a positive number of kbit/s'));
        }

        return (int) round((float) $value);
    }

    private function parseDataLimit(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RowRejected(__('data limit must be a positive number of GB'));
        }

        return (int) round((float) $value * 1024 ** 3);
    }

    private function parseExpiry(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new RowRejected(__('expiry date could not be understood'));
        }
    }

    /**
     * @return list<string>|null
     */
    private function parseDns(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        // One or more resolvers, split on whitespace, ; , or | -- a quoted
        // "1.1.1.1,9.9.9.9" cell arrives as one field, so comma is allowed too.
        $entries = array_values(array_filter(
            array_map('trim', preg_split('/[\s;,|]+/', $value) ?: []),
            fn (string $entry): bool => $entry !== '',
        ));

        foreach ($entries as $entry) {
            if (! filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RowRejected(__('DNS ":ip" is not a valid IPv4 address', ['ip' => $entry]));
            }
        }

        return $entries === [] ? null : $entries;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function looksLikeHeader(array $fields): bool
    {
        foreach ($fields as $field) {
            if (strtolower(trim($field)) === 'name') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function mapFromHeader(array $fields): array
    {
        $map = [];

        foreach ($fields as $index => $field) {
            $key = strtolower(trim($field));

            if (in_array($key, self::COLUMNS, true)) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private static function mapFromPositions(): array
    {
        return array_flip(self::COLUMNS);
    }
}
