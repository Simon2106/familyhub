<?php

namespace App\Services\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Sabre\VObject\Recur\RRuleIterator;
use Throwable;

/**
 * A series, turned into the days it actually happens on.
 *
 * The three things that make this more than "walk the RRULE":
 *
 *  - EXDATE. A week struck out of a series is not an occurrence, and iCloud
 *    expresses "delete just this one" that way.
 *  - RECURRENCE-ID overrides. An occurrence edited on its own is a second
 *    VEVENT sharing the UID; it replaces the generated one, and it may have
 *    been moved to another day entirely, so it cannot simply be matched by
 *    start time.
 *  - A cancelled override. iCloud sometimes says "this one is off" with a
 *    STATUS:CANCELLED override rather than an EXDATE; both have to mean the
 *    same thing here.
 *
 * Pure: it reads models and returns arrays, and writes nothing. That is what
 * makes the awkward cases testable without a calendar server.
 */
class OccurrenceExpander
{
    /**
     * A ceiling on one series, so an unbounded daily rule cannot run away.
     *
     * Eighteen months of daily is about 550; anything past this is a calendar
     * doing something no household does.
     */
    public const MAX_PER_SERIES = 800;

    /**
     * @param  Collection<int, Event>  $overrides  rows carrying RECURRENCE-ID
     * @return list<array<string, mixed>> rows ready for event_occurrences
     */
    public function expand(Event $master, Collection $overrides, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byOriginal = $this->overridesByOriginalStart($overrides);
        $excluded = $this->excludedDates($master);

        $rows = [];
        $claimed = [];

        foreach ($this->starts($master, $from, $to) as $start) {
            $key = $this->key($start);

            if (in_array($key, $excluded, true)) {
                continue;
            }

            $override = $byOriginal[$key] ?? null;

            if ($override) {
                // Handled in the override pass below, so that one moved out of
                // the window is still emitted and one moved in is not doubled.
                $claimed[] = $key;

                continue;
            }

            $rows[] = $this->row($master, $master, $start, $start, false);
        }

        foreach ($byOriginal as $key => $override) {
            if ($override->status === 'cancelled') {
                continue;
            }

            $start = CarbonImmutable::parse($override->start_at)->utc();

            if (! $start->betweenIncluded($from, $to)) {
                continue;
            }

            $rows[] = $this->row(
                $master,
                $override,
                $start,
                CarbonImmutable::parse($this->originalStartOf($override) ?? $start)->utc(),
                true,
            );
        }

        // An override moved onto a day the series also lands on would otherwise
        // be two rows fighting over the same unique key.
        return $this->deduplicate($rows);
    }

    /** A plain event is its own only occurrence. */
    public function single(Event $event): array
    {
        $start = CarbonImmutable::parse($event->start_at)->utc();

        return [$this->row($event, $event, $start, $start, false)];
    }

    /**
     * The moments the rule itself produces.
     *
     * @return list<CarbonImmutable>
     */
    public function starts(Event $master, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CarbonImmutable::parse($master->start_at)->utc();

        if (! $master->repeats()) {
            return $start->betweenIncluded($from, $to) ? [$start] : [];
        }

        try {
            $iterator = new RRuleIterator((string) $master->rrule, $start->toDateTime());
            $iterator->fastForward($from->toDateTime());
        } catch (Throwable) {
            // A rule we cannot read is better treated as a single event than
            // as a reason to lose the whole series.
            return $start->betweenIncluded($from, $to) ? [$start] : [];
        }

        $out = [];

        try {
            while ($iterator->valid() && count($out) < self::MAX_PER_SERIES) {
                $moment = CarbonImmutable::instance($iterator->current())->utc();

                if ($moment->greaterThan($to)) {
                    break;
                }

                $out[] = $moment;
                $iterator->next();
            }
        } catch (Throwable) {
            return $out;
        }

        return $out;
    }

    /**
     * Overrides keyed by the occurrence they stand in for.
     *
     * @param  Collection<int, Event>  $overrides
     * @return array<string, Event>
     */
    protected function overridesByOriginalStart(Collection $overrides): array
    {
        $out = [];

        foreach ($overrides as $override) {
            $original = $this->originalStartOf($override);

            if ($original === null) {
                continue;
            }

            $out[$this->key(CarbonImmutable::parse($original)->utc())] = $override;
        }

        return $out;
    }

    /** RECURRENCE-ID, as a moment. */
    protected function originalStartOf(Event $override): ?string
    {
        $value = trim((string) $override->recurrence_id);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The struck-out dates, as keys.
     *
     * @return list<string>
     */
    protected function excludedDates(Event $master): array
    {
        $out = [];

        foreach ((array) ($master->exdate ?? []) as $value) {
            try {
                $out[] = $this->key(CarbonImmutable::parse((string) $value)->utc());
            } catch (Throwable) {
                // A date we cannot read must not take the series with it.
            }
        }

        return $out;
    }

    /**
     * To the minute.
     *
     * EXDATE and RECURRENCE-ID are written in whatever timezone the client
     * used, and a comparison on seconds would miss a match that is plainly
     * the same occurrence.
     */
    protected function key(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i');
    }

    /** @return array<string, mixed> */
    protected function row(
        Event $master,
        Event $showing,
        CarbonImmutable $start,
        CarbonImmutable $original,
        bool $isOverride,
    ): array {
        $length = CarbonImmutable::parse($showing->start_at)->diffInSeconds(CarbonImmutable::parse($showing->end_at));

        return [
            'event_id' => $showing->id,
            'series_event_id' => $master->id,
            'calendar_id' => $master->calendar_id,
            'title' => $showing->title,
            'starts_at' => $start,
            // The master's own length, carried to every occurrence: a weekly
            // hour is an hour every week.
            'ends_at' => $start->addSeconds(max(0, (int) $length)),
            'all_day' => (bool) $showing->all_day,
            'location' => $showing->location,
            'original_starts_at' => $original,
            'is_override' => $isOverride,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function deduplicate(array $rows): array
    {
        $seen = [];
        $out = [];

        // Overrides last in, and they win: an edited occurrence is what the
        // family actually expects to see on that day.
        foreach (array_reverse($rows) as $row) {
            $key = $this->key($row['starts_at']);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $out[] = $row;
        }

        usort($out, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $out;
    }
}
