<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A day on the calendar, stored as a plain date.
 *
 * Eloquent's `date` cast reads a date but writes a full "Y-m-d H:i:s", because
 * storage goes through the model's shared date format. MySQL's DATE column
 * quietly truncates that, so nothing looks wrong — until the same query runs
 * against SQLite, where "2026-07-15 00:00:00" <= "2026-07-15" compares as
 * strings and is false, and a to-do due on the last day of a range vanishes.
 *
 * Writing the date the way it is compared removes the difference between the
 * two databases rather than papering over it in every query.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class CalendarDate implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->format('Y-m-d');
    }
}
