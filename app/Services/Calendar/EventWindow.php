<?php

namespace App\Services\Calendar;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Household;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What is on between two dates — every occurrence, not every row.
 *
 * Hands back Event models rather than occurrences on purpose. Eight readers
 * and a dozen Blade templates already speak Event: they take $event->title,
 * $event->members, $event->start_at, and $event->id to open the editor. Giving
 * them a differently-shaped object would have meant changing all of that at
 * once, which is how a change like this goes wrong.
 *
 * So each occurrence is hydrated onto its own Event instance with the
 * occurrence's times. They are read-only — see Event::booted(), which refuses
 * to save one — because a clone that could be saved would eventually be
 * saved, and would write one Tuesday's time onto the whole series.
 */
class EventWindow
{
    /**
     * @return Collection<int, Event>
     */
    public function between(
        Household $household,
        CarbonInterface $from,
        CarbonInterface $to,
        ?callable $tap = null,
        int $limit = 2000,
    ): Collection {
        $query = EventOccurrence::query()
            ->forHousehold($household)
            ->visible()
            ->overlapping($from, $to)
            ->whereHas('event', fn (Builder $q) => $q->where('status', '!=', 'cancelled'))
            ->with(['event.members', 'event.calendar.member'])
            ->orderBy('starts_at')
            ->limit($limit);

        if ($tap) {
            $tap($query);
        }

        return $query->get()->map(fn (EventOccurrence $o) => $this->asEvent($o))->values();
    }

    /**
     * The occurrence, wearing its event's clothes.
     *
     * Everything a reader draws comes from the occurrence — including the
     * title and location, so an edited Tuesday shows what it was edited to —
     * while the identity stays the event's, so tapping it opens the right
     * thing to edit.
     */
    public function asEvent(EventOccurrence $occurrence): Event
    {
        $event = $occurrence->event;

        if (! $event) {
            // Should not happen: the foreign key cascades. Belt and braces so
            // one bad row cannot empty a wall.
            $event = new Event;
        }

        $clone = $event->replicate([]);

        $clone->id = $event->id;
        $clone->exists = true;

        $clone->setRawAttributes([
            ...$event->getAttributes(),
            'title' => $occurrence->title,
            'location' => $occurrence->location,
            'start_at' => $occurrence->getRawOriginal('starts_at'),
            'end_at' => $occurrence->getRawOriginal('ends_at'),
            'all_day' => $occurrence->all_day,
        ], sync: true);

        $clone->setRelations($event->getRelations());

        // What a reader needs to tell two Tuesdays apart, and to know it is
        // looking at an expansion rather than a row.
        $clone->occurrence_id = $occurrence->id;
        $clone->occurrence_starts_at = $occurrence->starts_at;
        $clone->occurrence_is_override = $occurrence->is_override;
        $clone->isOccurrence = true;

        return $clone;
    }
}
