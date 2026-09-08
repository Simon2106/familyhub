<?php

namespace App\Services\Bins;

use App\Exceptions\IcalException;
use App\Models\BinCollection;
use App\Models\Household;
use App\Services\Ical\IcalEntry;
use App\Services\Ical\IcalFeed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the bin collections in step with wherever they come from.
 *
 * Two sources, one table. A subscribed calendar where the council publishes
 * one; a written-down fortnightly pattern where — as here — it publishes a PDF
 * and nothing else.
 *
 * Either way the result is replaced rather than merged: nobody edits these by
 * hand, so a round that has moved should disappear rather than linger next to
 * its replacement.
 */
class BinSchedule
{
    /** Far enough ahead to be useful, near enough that a changed round matters. */
    public const WEEKS_AHEAD = 12;

    public function __construct(protected IcalFeed $feed) {}

    /** @throws IcalException */
    public function sync(Household $household): int
    {
        $rows = match ($household->binSource()) {
            'ical' => $this->fromCalendar($household),
            'pattern' => $this->fromPattern($household),
            default => null,
        };

        if ($rows === null) {
            return 0;
        }

        return $this->store($household, $rows);
    }

    /**
     * @return list<array{on: string, name: string, kind: string}>|null
     *
     * @throws IcalException
     */
    protected function fromCalendar(Household $household): ?array
    {
        $url = $household->binCalendarUrl();

        if (! $url) {
            return null;
        }

        return $this->rows($this->feed->fetch($url), $household->todayLocal()->subWeek()->toDateString());
    }

    /** @return list<array{on: string, name: string, kind: string}>|null */
    protected function fromPattern(Household $household): ?array
    {
        $pattern = BinPattern::fromArray($household->binPatternSettings() ?? []);

        if (! $pattern || ! $pattern->isUsable()) {
            return null;
        }

        $from = $household->todayLocal()->subWeek();
        $override = $household->binOverride();
        $rows = [];

        foreach ($pattern->between($from, $from->addWeeks(self::WEEKS_AHEAD)) as $day) {
            // A bank holiday moves the whole day's collection, not one bin.
            $on = $override && $override['on'] === $day['on'] ? $override['moved_to'] : $day['on'];

            foreach ($day['kinds'] as $kind) {
                $rows[] = [
                    'on' => $on,
                    'name' => BinCollection::KINDS[$kind]['label'] ?? 'Bins',
                    'kind' => $kind,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{on: string, name: string, kind: string}>  $rows
     */
    protected function store(Household $household, array $rows): int
    {
        $from = $household->todayLocal()->subWeek()->toDateString();

        return DB::transaction(function () use ($household, $rows, $from) {
            // Only forward of a week ago: last month's collections are nobody's
            // business, and keeping a little history means a source that breaks
            // does not blank today's answer.
            BinCollection::where('household_id', $household->id)
                ->where('on', '>=', $from)
                ->delete();

            $written = 0;

            foreach ($rows as $row) {
                if ($row['on'] < $from) {
                    continue;
                }

                BinCollection::updateOrCreate(
                    ['household_id' => $household->id, 'on' => $row['on'], 'kind' => $row['kind']],
                    ['name' => $row['name']],
                );

                $written++;
            }

            return $written;
        });
    }

    /**
     * @param  Collection<int, IcalEntry>  $entries
     * @return list<array{on: string, name: string, kind: string}>
     */
    protected function rows(Collection $entries, string $from): array
    {
        $seen = [];
        $rows = [];

        foreach ($entries as $entry) {
            $on = $entry->startsOn->toDateString();

            if ($on < $from) {
                continue;
            }

            $kind = BinCollection::kindFor($entry->summary);
            $key = $on.'|'.$kind;

            // A feed listing the same bin twice on a day is a feed being
            // careless, not two collections.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = ['on' => $on, 'name' => $entry->summary, 'kind' => $kind];
        }

        return $rows;
    }
}
