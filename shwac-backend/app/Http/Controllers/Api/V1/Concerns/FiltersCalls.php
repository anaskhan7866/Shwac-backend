<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * V2 call filters shared by the Bookings and Service jobs lists:
 *   category_id, service_id, priority, technician_id, from/to (visit date),
 *   place (city or pincode) and form[key]=value (the service's own answers,
 *   e.g. form[service_type]=Gas Filling, form[warranty_status]=AMC, form[tonnage]=1.5 Ton).
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
                $q->whereHas('address', fn ($a) => preg_match('/^\d{6}$/', $place)
                    ? $a->where('pincode', $place)
                    : $a->where('city', 'like', '%'.$place.'%'));
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
        if (! $request->filled('priority') && ! $form && ! $min && ! $max) {
            return;
        }
        $onBooking = function (Builder $b) use ($request, $form, $min, $max) {
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
        };
        $viaBooking ? $q->whereHas('booking', $onBooking) : $onBooking($q);
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
