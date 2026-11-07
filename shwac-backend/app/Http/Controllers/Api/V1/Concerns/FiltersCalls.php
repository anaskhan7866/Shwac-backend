<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * V2 call filters shared by the Bookings and Service jobs lists:
 *   category_id, service_id, priority, technician_id, from/to (visit date),
 *   place (city or pincode) and the service's own answers:
 *   form[key]=value (select equals / multi-choice includes, e.g. form[warranty_status]=AMC),
 *   form_min[key] / form_max[key] (number range, e.g. TDS) and
 *   form_text[key] (free text contains, e.g. form_text[model_serial]=LG-15).
 */
trait FiltersCalls
{
    /** @param bool $viaBooking true for service jobs (answers and priority live on the booking) */
    protected function applyCallFilters(Builder $q, Request $request, bool $viaBooking = false): void
    {
        // A bad value in a hand-typed link gets a clear 422, never a server error.
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
            'technician_id' => ['nullable', 'integer'],
            'service_id' => ['nullable'],
            'service_id.*' => ['integer'],
            'place' => ['nullable', 'string', 'max:80'],
            'form_min' => ['nullable', 'array'],
            'form_min.*' => ['nullable', 'numeric'],
            'form_max' => ['nullable', 'array'],
            'form_max.*' => ['nullable', 'numeric'],
            'form_text' => ['nullable', 'array'],
            'form_text.*' => ['nullable', 'string', 'max:60'],
        ]);
        if ($request->filled('category_id')) {
            $q->whereHas('service', fn ($s) => $s->where('service_category_id', $request->integer('category_id')));
        }
        if ($request->filled('service_id')) {
            $q->whereIn('service_id', (array) $request->input('service_id'));
        }
        if ($request->filled('from')) {
            $q->whereDate('scheduled_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('scheduled_date', '<=', $request->date('to'));
        }
        if (trim((string) $request->input('place', '')) !== '') {
            // % and _ are LIKE wildcards: typed by a user they must not mean "match anything".
            $place = trim(str_replace(['%', '_'], '', (string) $request->input('place')));
            if ($place === '') {
                $q->whereRaw('1 = 0');
            } else {
                // LOWER on both sides: PostgreSQL (live) LIKE is case-sensitive, so "agra" must still find "Agra".
                $q->whereHas('address', fn ($a) => preg_match('/^\d{6}$/', $place)
                    ? $a->where('pincode', $place)
                    : $a->whereRaw('LOWER(city) LIKE ?', ['%'.mb_strtolower($place).'%']));
            }
        }
        if ($request->filled('technician_id')) {
            $tech = $request->integer('technician_id');
            $viaBooking
                ? $q->where('technician_id', $tech)
                : $q->whereHas('jobs', fn ($j) => $j->where('technician_id', $tech));
        }

        $keyOk = fn ($v, $k) => is_string($k) && preg_match('/^[a-z0-9_]{1,40}$/', $k) && is_scalar($v) && $v !== '';
        $form = array_filter((array) $request->input('form', []), $keyOk, ARRAY_FILTER_USE_BOTH);
        $min = array_filter((array) $request->input('form_min', []), $keyOk, ARRAY_FILTER_USE_BOTH);
        $max = array_filter((array) $request->input('form_max', []), $keyOk, ARRAY_FILTER_USE_BOTH);
        $text = array_filter((array) $request->input('form_text', []), fn ($v, $k) => $keyOk($v, $k) && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH);
        if (! $request->filled('priority') && ! $form && ! $min && ! $max && ! $text) {
            return;
        }
        $onBooking = function (Builder $b) use ($request, $form, $min, $max, $text) {
            if ($request->filled('priority')) {
                $b->whereIn('priority', array_values(array_intersect((array) $request->input('priority'), Booking::PRIORITIES)));
            }
            foreach ($form as $key => $value) {
                // Keys are checked above; values are bound, never interpolated.
                // A select answer equals the value; a multi-choice answer (a JSON list) contains it.
                $needle = str_replace(['%', '_'], '', (string) $value);
                $b->where(fn ($w) => $w->where('form_data->'.$key, (string) $value)
                    ->orWhere('form_data->'.$key, 'like', '%"'.$needle.'"%'));
            }
            // PDO binds the limit as text; cast it so it compares as a number on every database
            // (in SQLite a number compared with text is always "smaller").
            foreach ($min as $key => $value) {
                $b->whereRaw($this->numericAnswer($b, $key).' >= CAST(? AS DECIMAL(14,2))', [(string) (float) $value]);
            }
            foreach ($max as $key => $value) {
                $b->whereRaw($this->numericAnswer($b, $key).' <= CAST(? AS DECIMAL(14,2))', [(string) (float) $value]);
            }
            // Free-text answers (Model & Serial, Inverter Brand / Serial…): "contains", any letter case.
            // % and _ typed by a user are removed so they never mean "match anything".
            foreach ($text as $key => $value) {
                $needle = mb_strtolower(trim(str_replace(['%', '_'], '', (string) $value)));
                if ($needle === '') {
                    $b->whereRaw('1 = 0');
                    continue;
                }
                $b->whereRaw('LOWER('.$this->textAnswer($b, $key).') LIKE ?', ['%'.$needle.'%']);
            }
        };
        $viaBooking ? $q->whereHas('booking', $onBooking) : $onBooking($q);
    }

    /** SQL for a text answer in bookings.form_data. $key is already limited to [a-z0-9_]; no "?" inside. */
    private function textAnswer(Builder $b, string $key): string
    {
        return match ($b->getConnection()->getDriverName()) {
            'pgsql' => "(form_data->>'{$key}')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(form_data, '$.\"{$key}\"'))",
            default => "json_extract(form_data, '$.\"{$key}\"')",
        };
    }

    /**
     * SQL for a number answer in bookings.form_data (e.g. TDS). $key is already limited to [a-z0-9_].
     * A non-number (or missing) answer becomes NULL, so it never matches a range and never errors.
     */
    private function numericAnswer(Builder $b, string $key): string
    {
        return match ($b->getConnection()->getDriverName()) {
            // No "?" in this SQL: PDO would read it as a bind placeholder.
            'pgsql' => "(CASE WHEN (form_data->>'{$key}') ~ '^-{0,1}[0-9]+([.][0-9]+){0,1}$' THEN CAST(form_data->>'{$key}' AS NUMERIC) END)",
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(form_data, '$.\"{$key}\"')) AS DECIMAL(14,2))",
            default => "(CASE WHEN json_type(form_data, '$.\"{$key}\"') IN ('integer', 'real') OR (json_extract(form_data, '$.\"{$key}\"') GLOB '[0-9]*' AND json_extract(form_data, '$.\"{$key}\"') NOT GLOB '*[^0-9.]*') THEN CAST(json_extract(form_data, '$.\"{$key}\"') AS REAL) END)",
        };
    }
}
