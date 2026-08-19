<?php

namespace App\Services\ServiceUsers;

/**
 * The outcome of parsing an import CSV: the rows that validated (in the
 * canonical units a ServiceUser stores) and the ones that did not, each with a
 * human line number and reason. Carries no database state -- CsvUserImporter
 * produces it without touching the database.
 */
class CsvUserImportResult
{
    /**
     * @param  list<array<string, mixed>>  $valid
     * @param  list<array{line: int, reason: string}>  $skipped
     */
    public function __construct(
        public readonly array $valid,
        public readonly array $skipped,
    ) {}

    public function createdCount(): int
    {
        return count($this->valid);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
