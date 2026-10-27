<?php

namespace App\Support;

/**
 * Money helpers. All arithmetic happens in integer paise to avoid float drift;
 * values are converted back to 2-decimal strings for storage and JSON.
 */
final class Money
{
    public static function toPaise(int|float|string|null $rupees): int
    {
        if ($rupees === null || $rupees === '') {
            return 0;
        }
        // bcmath-free: go through a fixed 2-decimal string to avoid binary float errors
        $str = is_string($rupees) ? $rupees : number_format((float) $rupees, 2, '.', '');
        $negative = str_starts_with($str, '-');
        $str = ltrim($str, '-');
        [$whole, $frac] = array_pad(explode('.', $str, 2), 2, '0');
        $frac = substr(str_pad($frac, 3, '0'), 0, 3);
        $paise = ((int) $whole) * 100 + intdiv((int) $frac, 10) + ((int) $frac[2] >= 5 ? 1 : 0);

        return $negative ? -$paise : $paise;
    }

    public static function fromPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return sprintf('%s%d.%02d', $sign, intdiv($paise, 100), $paise % 100);
    }

    /** Round-half-up of (paise × rate%) to whole paise. */
    public static function percentOf(int $paise, float|string $ratePercent): int
    {
        // 18% → 18000, 0.125% → 125 (milli-percent keeps 3 decimals, enough for half of 0.25%)
        $milli = (int) round(((float) $ratePercent) * 1000);

        return intdiv($paise * $milli + ($paise >= 0 ? 50000 : -50000), 100000);
    }

    public static function format(int|float|string $rupees): string
    {
        return '₹'.number_format((float) $rupees, 2);
    }
}
