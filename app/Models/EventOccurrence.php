<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated instance of an event.
 *
 * Written at sync time by OccurrenceStore, never by hand. A non-repeating
 * event has exactly one of these, which is what lets every reader ask the
 * same question of the same table instead of two questions of two.
 */
#[Fillable([
    'event_id', 'series_event_id', 'calendar_id', 'title',
    'starts_at', 'ends_at', 'all_day', 'location',
    'original_starts_at', 'is_override',
])]
class EventOccurrence extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
            'original_starts_at' => UtcDateTime::class,
            'all_day' => 'boolean',
            'is_override' => 'boolean',
        ];
    }

    /** The row a tap should open — the override where there is one. */
    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'series_event_id');
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * Occurrences touching the window at all, not merely starting in it —
     * an overnight or multi-day one must show on every day it covers.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /** @param Builder<EventOccurrence> $query */
    public function scopeForHousehold(Builder $query, Household $household): void
    {
        $query->whereHas('calendar', fn ($q) => $q
            ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)));
    }

    /** @param Builder<EventOccurrence> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereHas('calendar', fn ($q) => $q->where('is_visible', true));
    }
}
