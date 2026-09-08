<?php

namespace App\Services\Schools;

use App\Exceptions\IcalException;
use App\Models\Household;
use App\Models\Place;
use App\Models\SchoolDate;
use App\Services\Ical\IcalEntry;
use App\Services\Ical\IcalFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Term dates, and what to show because of them.
 *
 * Term time is the default state of a household with children in it, so the
 * wall says nothing during it. What it shows is the exceptions: the holidays
 * between terms, and the INSET days in the middle of one — the days somebody
 * has to have arranged something for.
 */
class SchoolCalendar
{
    public function __construct(protected IcalFeed $feed) {}

    /**
     * Every closure across the household's schools in a range.
     *
     * @return Collection<int, SchoolClosure>
     */
    public function closures(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $closures = collect();

        foreach ($this->schools($household) as $place) {
            $closures = $closures->merge($this->forPlace($place, $from, $to));
        }

        return $closures
            ->sortBy([
                fn (SchoolClosure $c) => $c->startsOn->toDateString(),
                fn (SchoolClosure $c) => $c->code,
            ])
            ->values();
    }

    /** @return Collection<int, SchoolClosure> */
    public function forPlace(Place $place, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $dates = $place->schoolDates()->get();
        $closures = collect();

        // Holidays are the gaps between terms rather than rows of their own,
        // so half term cannot be forgotten — it is simply the space between
        // two terms somebody did remember to enter.
        $terms = $dates->where('kind', 'term')->sortBy(fn (SchoolDate $d) => $d->starts_on->toDateString())->values();

        foreach ($terms as $index => $term) {
            $next = $terms[$index + 1] ?? null;

            if (! $next) {
                continue;
            }

            $start = $term->ends_on->addDay();
            $end = $next->starts_on->subDay();

            if ($start->greaterThan($end)) {
                continue;
            }

            $closures->push(new SchoolClosure(
                code: $place->code(),
                label: $this->holidayName($term, $next),
                kind: 'holiday',
                startsOn: $start,
                endsOn: $end,
            ));
        }

        foreach ($dates->whereIn('kind', ['inset', 'closed']) as $date) {
            $closures->push(new SchoolClosure(
                code: $place->code(),
                label: $date->name,
                kind: $date->kind === 'inset' ? 'inset' : 'holiday',
                startsOn: $date->starts_on,
                endsOn: $date->ends_on,
            ));
        }

        return $closures->filter(
            fn (SchoolClosure $c) => $c->startsOn->toDateString() <= $to->toDateString()
                && $c->endsOn->toDateString() >= $from->toDateString()
        )->values();
    }

    /**
     * What is happening at each school in the next few days.
     *
     * @return Collection<int, array{code: string, label: string, on: CarbonImmutable}>
     */
    public function turningPoints(Household $household, CarbonImmutable $today, int $withinDays = 3): Collection
    {
        $horizon = $today->addDays($withinDays);
        $points = collect();

        foreach ($this->schools($household) as $place) {
            foreach ($place->schoolDates()->terms()->get() as $term) {
                if ($this->between($term->ends_on, $today, $horizon)) {
                    $points->push(['code' => $place->code(), 'label' => 'Last day of term', 'on' => $term->ends_on]);
                }

                if ($this->between($term->starts_on, $today, $horizon)) {
                    $points->push(['code' => $place->code(), 'label' => 'Back to school', 'on' => $term->starts_on]);
                }
            }
        }

        return $points->sortBy(fn (array $p) => $p['on']->toDateString())->values();
    }

    /**
     * Import a school's own feed.
     *
     * Only ever replaces what the feed put there before; anything typed in by
     * hand survives, because a school that changes its feed should not be able
     * to delete dates somebody read off a PDF.
     *
     * @throws IcalException
     */
    public function importFeed(Place $place): int
    {
        if (blank($place->term_ical_url)) {
            return 0;
        }

        $entries = $this->feed->fetch($place->term_ical_url);

        $place->schoolDates()->where('source', 'ical')->delete();

        $written = 0;

        foreach ($entries as $entry) {
            $place->schoolDates()->create([
                'kind' => $this->kindOf($entry),
                'name' => $entry->summary,
                'starts_on' => $entry->startsOn->toDateString(),
                'ends_on' => $entry->endsOn->toDateString(),
                'source' => 'ical',
            ]);

            $written++;
        }

        return $written;
    }

    /** @return Collection<int, Place> */
    protected function schools(Household $household): Collection
    {
        return $household->places()->where('type', 'school')->with('schoolDates')->get();
    }

    /**
     * What a feed entry is.
     *
     * A school calendar is written for parents, not for parsers, so this reads
     * the wording rather than pretending there is a standard.
     */
    protected function kindOf(IcalEntry $entry): string
    {
        $text = mb_strtolower($entry->summary);

        if (str_contains($text, 'inset') || str_contains($text, 'training')
            || str_contains($text, 'staff development') || str_contains($text, 'occasional day')) {
            return 'inset';
        }

        // "Autumn term" spanning weeks is a term; "Term starts" on one day is
        // a marker, and marking it closed would shut the school for a day.
        if (str_contains($text, 'term') && $entry->days() > 5) {
            return 'term';
        }

        return 'closed';
    }

    protected function holidayName(SchoolDate $before, SchoolDate $after): string
    {
        $gap = (int) $before->ends_on->diffInDays($after->starts_on);

        // A week or so between two terms of the same season is half term;
        // anything longer is the holiday between them.
        return $gap <= 12 ? 'Half term' : 'Holidays';
    }

    protected function between(CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $date->toDateString() >= $from->toDateString()
            && $date->toDateString() <= $to->toDateString();
    }
}
