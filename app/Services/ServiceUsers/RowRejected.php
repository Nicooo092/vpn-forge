<?php

namespace App\Services\ServiceUsers;

use RuntimeException;

/**
 * Internal control-flow signal: a single CSV row failed validation, carrying
 * the human reason. Never leaves CsvUserImporter -- parse() catches it and
 * turns it into a skipped-row entry.
 */
class RowRejected extends RuntimeException
{
}
