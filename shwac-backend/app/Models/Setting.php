<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value system configuration (company profile, branding, tax defaults, SLA flags).
 * Values are JSON so a key can hold a scalar or a structured object.
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['value' => 'json'];

    private const CACHE_KEY = 'settings.all';

    public static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    /** Dot-notation lookup: Setting::get('company.name') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::cached();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        [$root, $rest] = array_pad(explode('.', $key, 2), 2, null);

        return $rest !== null && isset($all[$root]) && is_array($all[$root])
            ? Arr::get($all[$root], $rest, $default)
            : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
