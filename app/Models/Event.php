<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Services\Calendar\OccurrenceStore;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['calendar_id', 'external_id', 'recurrence_id', 'href', 'etag', 'title', 'start_at', 'end_at', 'all_day', 'location', 'notes', 'rrule', 'exdate', 'source_hash', 'status', 'attribution', 'needs_push', 'pushed_at'])]
class Event extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Always stored UTC, whatever zone the source used. See UtcDateTime.
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'all_day' => 'boolean',
            // The dates struck out of a series, as ISO strings.
            'exdate' => 'array',
            'needs_push' => 'boolean',
            'pushed_at' => 'datetime',
        ];
    }

    /**
     * Set on the copies EventWindow hands out, one per occurrence.
     *
     * Not a column. It exists so that saving one is refused rather than
     * quietly writing a single Tuesday's time over the whole series.
     */
    public bool $isOccurrence = false;

    /** Set while a bulk writer is going to rebuild the series itself. */
    protected static bool $rebuildingSuppressed = false;

    /**
     * Write a batch of events without rebuilding occurrences for each one.
     *
     * The caller takes on the job of rebuilding afterwards. Used by the sync,
     * where one .ics is several rows and the useful unit is the resource.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function withoutOccurrenceRebuild(callable $work)
    {
        static::$rebuildingSuppressed = true;

        try {
            return $work();
        } finally {
            static::$rebuildingSuppressed = false;
        }
    }

    protected static function booted(): void
    {
        // uid_hash backs the unique key; external_id is too long to index directly.
        static::saving(function (Event $event) {
            if ($event->isOccurrence) {
                throw new LogicException(
                    'This is one occurrence of a repeating event, not a row. '
                    .'Load the event itself before saving it.'
                );
            }

            $event->uid_hash = static::uidHash($event->external_id, $event->recurrence_id);
        });

        // Every write rebuilds the series it belongs to, so the occurrence
        // table can never be stale — whoever did the writing. The CalDAV
        // sync suppresses this and rebuilds once per resource instead, since
        // a series is several rows and rebuilding it per row is the same
        // answer several times.
        static::saved(function (Event $event) {
            if (static::$rebuildingSuppressed) {
                return;
            }

            app(OccurrenceStore::class)->rebuildFor($event);
        });

        // An event's occurrences are rebuilt from it, so they go with it.
        static::deleted(function (Event $event) {
            $event->occurrences()->delete();
            EventOccurrence::where('event_id', $event->id)->delete();

            // A deleted override leaves the rest of the series behind, and
            // that Tuesday goes back to what the rule says.
            if ($event->isOverride() && ! static::$rebuildingSuppressed) {
                app(OccurrenceStore::class)->rebuildFor($event);
            }
        });
    }

    public static function uidHash(string $externalId, ?string $recurrenceId = null): string
    {
        return hash('sha256', $externalId.'|'.($recurrenceId ?? ''));
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * The members this event concerns.
     *
     * Many-to-many because "SW + JW dentist" belongs to both of them.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('reason')->withTimestamps();
    }

    /** True once a person has chosen the members by hand. */
    public function attributionIsManual(): bool
    {
        return $this->attribution === 'manual';
    }

    /**
     * Events that overlap the given window at all, not merely those starting in it —
     * a multi-day or overnight event must still show on each day it touches.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('start_at', '<', $to)->where('end_at', '>', $from);
    }

    /** @param Builder<Event> $query */
    public function scopeNotCancelled(Builder $query): void
    {
        $query->where('status', '!=', 'cancelled');
    }

    /** @return HasMany<EventOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class, 'series_event_id');
    }

    public function repeats(): bool
    {
        return filled($this->rrule);
    }

    /** A VEVENT carrying RECURRENCE-ID: one occurrence edited on its own. */
    public function isOverride(): bool
    {
        return filled($this->recurrence_id);
    }

    /** The row the series hangs off: same UID, no RECURRENCE-ID. */
    public function isSeriesMaster(): bool
    {
        return ! $this->isOverride();
    }

    /**
     * Every row sharing this event's UID inside the same calendar.
     *
     * In CalDAV a series and its edited occurrences are one resource holding
     * several VEVENTs, so these are siblings in iCloud as well as here — which
     * is why a write has to send all of them at once.
     *
     * @return Builder<Event>
     */
    public function seriesQuery(): Builder
    {
        return static::query()
            ->where('calendar_id', $this->calendar_id)
            ->where('external_id', $this->external_id);
    }
}
