<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a datetime as UTC, whatever zone it arrives in.
 *
 * Eloquent's built-in `datetime` cast writes the wall-clock value of the Carbon
 * instance it is given and throws the zone away, so a Europe/London 08:15
 * silently becomes 08:15 UTC — an hour adrift for half the year. Calendar feeds
 * (Google, CalDAV) hand us times in whatever zone the organiser used, so the
 * conversion has to happen on the way in, not at display time.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class UtcDateTime implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            // A bare string carries no zone, so it is read as UTC — the zone
            // everything is stored in.
            : CarbonImmutable::parse($value, 'UTC');

        return $date->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
