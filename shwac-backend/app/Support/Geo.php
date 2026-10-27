<?php

namespace App\Support;

final class Geo
{
    /** Great-circle distance in kilometres. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Visit order by repeatedly going to the nearest unvisited stop (good enough for a
     * day's 4–12 jobs; Google Routes API can replace this later for road distances).
     * Stops without coordinates keep their original order at the end.
     *
     * @template T
     * @param  array<int, T>  $stops
     * @param  callable(T): array{0: ?float, 1: ?float}  $coords
     * @return array<int, array{stop: T, leg_km: ?float}>
     */
    public static function nearestNeighbourRoute(?float $startLat, ?float $startLng, array $stops, callable $coords): array
    {
        $located = [];
        $unlocated = [];
        foreach ($stops as $s) {
            [$la, $ln] = $coords($s);
            if ($la === null || $ln === null) {
                $unlocated[] = $s;
            } else {
                $located[] = [$s, (float) $la, (float) $ln];
            }
        }

        $route = [];
        [$curLat, $curLng] = [$startLat, $startLng];
        if ($curLat === null && $located) {
            [, $curLat, $curLng] = $located[0];
        }

        while ($located) {
            $best = 0;
            $bestKm = INF;
            foreach ($located as $i => [, $la, $ln]) {
                $d = self::km($curLat, $curLng, $la, $ln);
                if ($d < $bestKm) {
                    $bestKm = $d;
                    $best = $i;
                }
            }
            [$stop, $curLat, $curLng] = $located[$best];
            $route[] = ['stop' => $stop, 'leg_km' => round($bestKm, 1)];
            array_splice($located, $best, 1);
        }

        foreach ($unlocated as $s) {
            $route[] = ['stop' => $s, 'leg_km' => null];
        }

        return $route;
    }
}
