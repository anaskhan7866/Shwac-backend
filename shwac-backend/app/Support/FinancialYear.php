<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Indian financial year runs 1 April – 31 March.
 * Code is the short form used in document numbers: FY 2026-27 → "2627".
 */
final class FinancialYear
{
    public static function startYear(CarbonInterface $date): int
    {
        return $date->month >= 4 ? $date->year : $date->year - 1;
    }

    public static function code(CarbonInterface $date): string
    {
        $start = self::startYear($date);

        return sprintf('%02d%02d', $start % 100, ($start + 1) % 100);
    }

    public static function label(CarbonInterface $date): string
    {
        $start = self::startYear($date);

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }
}
