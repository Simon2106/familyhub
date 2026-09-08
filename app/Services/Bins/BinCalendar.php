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
 * Keeps the bin collections in step with the council's feed.
 *
 * Replaced rather than merged: the council's calendar is the truth and nobody
 * edits these by hand, so a round that has been moved should disappear rather
 * than linger next to its replacement.
 */
class BinCalendar
{
    public function __construct(protected IcalFeed $feed) {}

    /** @throws IcalException */
    public function sync(Household $household): int
    {
        $url = $household->binCalendarUrl();

        if (! $url) {
            return 0;
        }

        $entries = $this->feed->fetch($url);
        $from = $household->todayLocal()->subWeek()->toDateString();

        return DB::transaction(function () use ($household, $entries, $from) {
            // Only forward of a week ago: last month's collections are nobody's
            // business, and keeping a little history means a feed that breaks
            // does not blank today's answer.
            BinCollection::where('household_id', $household->id)
                ->where('on', '>=', $from)
                ->delete();

            $rows = $this->rows($entries, $from);

            foreach ($rows as $row) {
                BinCollection::create($row + ['household_id' => $household->id]);
            }

            return count($rows);
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
