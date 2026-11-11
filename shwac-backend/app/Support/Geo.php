<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class Geo
{
    /** GST state codes (same list as the customer app's data/states.dart). */
    public const GST_STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    /** "Rajasthan" → "08". Also accepts "NCT of Delhi", "Orissa" and other spellings OpenStreetMap uses. */
    public static function stateCode(?string $state): ?string
    {
        $s = strtolower(trim((string) $state));
        $s = ['nct of delhi' => 'delhi', 'national capital territory of delhi' => 'delhi', 'orissa' => 'odisha',
            'pondicherry' => 'puducherry', 'jammu & kashmir' => 'jammu and kashmir'][$s] ?? $s;
        foreach (self::GST_STATES as $code => $name) {
            if (strtolower($name) === $s) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Phone GPS → OpenStreetMap address parts (free, no key). Cached per ~100 m for a day.
     * Returns [] when the lookup fails.
     */
    public static function reverse(float $lat, float $lng): array
    {
        $key = sprintf('geo.reverse.%.3f,%.3f', $lat, $lng);
        $a = Cache::get($key);
        if ($a !== null) {
            return $a;
        }
        try {
            $res = Http::timeout(8)->withHeaders(['User-Agent' => 'SHWAC-Water-Care/1.0 ('.config('app.url').')', 'Accept-Language' => 'en'])
                ->get('https://nominatim.openstreetmap.org/reverse', ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'addressdetails' => 1, 'zoom' => 18]);
            $a = $res->successful() ? (array) $res->json('address', []) : [];
        } catch (\Throwable) {
            $a = [];
        }
        if ($a) {
            Cache::put($key, $a, now()->addDay());
        }

        return $a;
    }

    /**
     * Fields for a customer address from GPS, or null if the spot can't be turned into a usable
     * address (no street/area, city or known state). $pincode overrides the looked-up PIN code.
     */
    public static function addressFromGps(float $lat, float $lng, ?string $pincode = null): ?array
    {
        $a = self::reverse($lat, $lng);
        $pin = $pincode ?: preg_replace('/\D/', '', (string) ($a['postcode'] ?? ''));
        $area = $a['neighbourhood'] ?? $a['suburb'] ?? $a['quarter'] ?? $a['residential'] ?? null;
        $street = trim(implode(' ', array_filter([$a['house_number'] ?? null, $a['road'] ?? null])));
        $line1 = $street ?: ($a['amenity'] ?? $a['building'] ?? $area);
        $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? $a['state_district'] ?? null;
        $code = self::stateCode($a['state'] ?? null);
        if (! $line1 || ! $city || ! $code || strlen((string) $pin) !== 6) {
            return null;
        }

        return [
            'label' => 'Home',
            'line1' => mb_substr($line1, 0, 255),
            'line2' => $street && $area ? mb_substr($area, 0, 255) : null,
            'landmark' => isset($a['amenity']) && $street ? mb_substr($a['amenity'], 0, 120) : null,
            'city' => mb_substr($city, 0, 80),
            'district' => isset($a['state_district']) ? mb_substr($a['state_district'], 0, 80) : null,
            'state' => self::GST_STATES[$code],
            'state_code' => $code,
            'pincode' => $pin,
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
        ];
    }
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
