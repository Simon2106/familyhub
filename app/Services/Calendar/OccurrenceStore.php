<?php

namespace App\Services\Calendar;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps event_occurrences in step with events.
 *
 * Called after a sync writes rows, and nightly to roll the window forward.
 * Rebuilding a series is a delete and an insert rather than a diff: a series
 * is a handful of rows, and a diff that gets an EXDATE wrong leaves a ghost
 * on somebody's wall that nothing will ever clear.
 */
class OccurrenceStore
{
    /** How far back occurrences are kept. Enough for "what did we do?". */
    public const MONTHS_BACK = 1;

    /** And forward. Far enough for next year's holidays to be visible. */
    public const MONTHS_AHEAD = 18;

    public function __construct(protected OccurrenceExpander $expander) {}

    public function windowFrom(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subMonths(self::MONTHS_BACK)->startOfDay();
    }

    public function windowTo(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addMonths(self::MONTHS_AHEAD)->endOfDay();
    }

    /**
     * Rebuild whichever series this event belongs to.
     *
     * Takes any row of a series — master or override — because a sync that
     * has just written an override has no reason to know which is which.
     */
    public function rebuildFor(Event $event, ?CarbonImmutable $now = null): int
    {
        $master = $this->masterOf($event);

        if (! $master) {
            // An override with no master yet: the sync will write one in the
            // same pass, and rebuilding then will pick this up.
            return 0;
        }

        return $this->rebuildSeries($master, $now);
    }

    public function rebuildSeries(Event $master, ?CarbonImmutable $now = null): int
    {
        $from = $this->windowFrom($now);
        $to = $this->windowTo($now);

        $rows = $master->repeats()
            ? $this->expander->expand($master, $this->overridesOf($master), $from, $to)
            : $this->singleRows($master, $from, $to);

        return DB::transaction(function () use ($master, $rows) {
            EventOccurrence::where('series_event_id', $master->id)->delete();

            if ($rows === []) {
                return 0;
            }

            $now = now();

            EventOccurrence::insert(array_map(
                fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
                array_map(fn (array $row) => $this->forInsert($row), $rows),
            ));

            return count($rows);
        });
    }

    /**
     * Every series held in one .ics resource.
     *
     * A CalDAV resource is a whole recurrence set — master plus every edited
     * occurrence — so this is the unit the sync has just finished writing and
     * the only point at which all of it is known together.
     */
    public function rebuildForResource(Calendar $calendar, ?string $href, ?CarbonImmutable $now = null): int
    {
        if (blank($href)) {
            return 0;
        }

        $written = 0;

        Event::query()
            ->where('calendar_id', $calendar->id)
            ->where('href', $href)
            ->whereNull('recurrence_id')
            ->each(function (Event $master) use (&$written, $now) {
                $written += $this->rebuildSeries($master, $now);
            });

        return $written;
    }

    /** Everything in one calendar. Used after a full resync. */
    public function rebuildCalendar(Calendar $calendar, ?CarbonImmutable $now = null): int
    {
        $written = 0;

        Event::query()
            ->where('calendar_id', $calendar->id)
            ->whereNull('recurrence_id')
            ->chunkById(200, function ($masters) use (&$written, $now) {
                foreach ($masters as $master) {
                    $written += $this->rebuildSeries($master, $now);
                }
            });

        // Overrides whose master has gone would otherwise never be expanded.
        $this->rebuildOrphanedOverrides($calendar, $now);

        return $written;
    }

    public function rebuildHousehold(Household $household, ?CarbonImmutable $now = null): int
    {
        $written = 0;

        Calendar::query()
            ->whereHas('account', fn ($q) => $q->where('household_id', $household->id))
            ->each(function (Calendar $calendar) use (&$written, $now) {
                $written += $this->rebuildCalendar($calendar, $now);
            });

        return $written;
    }

    public function forget(Event $event): void
    {
        EventOccurrence::where('series_event_id', $event->id)->delete();
        EventOccurrence::where('event_id', $event->id)->delete();
    }

    /**
     * A single event still gets a row, so every reader can ask one table.
     *
     * @return list<array<string, mixed>>
     */
    protected function singleRows(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // An override is expanded with its series, never on its own.
        if ($event->isOverride()) {
            return [];
        }

        $start = CarbonImmutable::parse($event->start_at)->utc();
        $end = CarbonImmutable::parse($event->end_at)->utc();

        // Overlapping rather than starting inside: a holiday that began last
        // month is still on this month's calendar.
        if ($start->greaterThan($to) || $end->lessThan($from)) {
            return [];
        }

        return $this->expander->single($event);
    }

    protected function masterOf(Event $event): ?Event
    {
        if ($event->isSeriesMaster()) {
            return $event;
        }

        return $event->seriesQuery()->whereNull('recurrence_id')->first();
    }

    /** @return Collection<int, Event> */
    protected function overridesOf(Event $master)
    {
        return $master->seriesQuery()->whereNotNull('recurrence_id')->get();
    }

    /**
     * An override left behind by a master that has been deleted.
     *
     * Rare, and worth handling: iCloud can delete a series and leave the
     * exceptions for a sweep that has not happened yet, and an occurrence
     * nobody can see is better than one nobody can explain.
     */
    protected function rebuildOrphanedOverrides(Calendar $calendar, ?CarbonImmutable $now): void
    {
        Event::query()
            ->where('calendar_id', $calendar->id)
            ->whereNotNull('recurrence_id')
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('events as masters')
                ->whereColumn('masters.calendar_id', 'events.calendar_id')
                ->whereColumn('masters.external_id', 'events.external_id')
                ->whereNull('masters.recurrence_id'))
            ->each(function (Event $orphan) use ($now) {
                $rows = $this->singleRowsForOrphan($orphan, $now);

                EventOccurrence::where('series_event_id', $orphan->id)->delete();

                if ($rows !== []) {
                    $stamp = now();

                    EventOccurrence::insert(array_map(
                        fn (array $row) => $this->forInsert($row) + ['created_at' => $stamp, 'updated_at' => $stamp],
                        $rows,
                    ));
                }
            });
    }

    /** @return list<array<string, mixed>> */
    protected function singleRowsForOrphan(Event $orphan, ?CarbonImmutable $now): array
    {
        $start = CarbonImmutable::parse($orphan->start_at)->utc();

        if ($orphan->status === 'cancelled') {
            return [];
        }

        if (! $start->betweenIncluded($this->windowFrom($now), $this->windowTo($now))) {
            return [];
        }

        return $this->expander->single($orphan);
    }

    /** @param array<string, mixed> $row */
    protected function forInsert(array $row): array
    {
        return [
            ...$row,
            'starts_at' => $row['starts_at']->utc()->toDateTimeString(),
            'ends_at' => $row['ends_at']->utc()->toDateTimeString(),
            'original_starts_at' => $row['original_starts_at']?->utc()->toDateTimeString(),
        ];
    }
}
