<?php

namespace App\Services\Todos;

use App\Exceptions\CalDavException;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Services\CalDav\CalDavManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Keeps an optional iCloud reminder in step with a dated to-do.
 *
 * The wall is the household's real to-do list, but phones are not looking at
 * it. When the household turns this on, accepting a dated task also puts an
 * all-day "Reminder: …" event in a chosen calendar on the day the to-do starts
 * showing — so the deadline reaches everyone's pocket, not just the kitchen.
 *
 * There is exactly one mirrored event per to-do, tracked by mirror_event_id.
 * Every change routes through sync(), which makes iCloud match the to-do
 * rather than trying to work out which edit just happened.
 */
class DeadlineMirror
{
    public function __construct(protected CalDavManager $caldav) {}

    public function sync(ChecklistItem $item): void
    {
        $wanted = $this->wants($item);
        $existing = $item->mirrorEvent;

        if (! $wanted) {
            $this->remove($item);

            return;
        }

        $existing ? $this->move($item, $existing) : $this->create($item);
    }

    /** Drop the reminder for a to-do that is going away. */
    public function forget(?int $eventId): void
    {
        if (! $eventId || ! ($event = Event::with('calendar.account')->find($eventId))) {
            return;
        }

        $this->attempt(fn () => $this->caldav->writer($event->calendar->account)->delete($event));
    }

    public function remove(ChecklistItem $item): void
    {
        if ($item->mirror_event_id === null) {
            return;
        }

        $this->forget($item->mirror_event_id);

        $item->forceFill(['mirror_event_id' => null])->saveQuietly();
    }

    /**
     * A to-do earns a reminder while it is open, dated, and the household
     * wants them. A ticked one loses it — that is what removes it from
     * everyone's phone.
     */
    protected function wants(ChecklistItem $item): bool
    {
        return ! $item->is_done
            && $item->due_on !== null
            && $this->household($item)->mirrorsDeadlineTasks();
    }

    protected function create(ChecklistItem $item): void
    {
        $calendar = $this->household($item)->mirrorCalendar();

        if (! $calendar) {
            return;
        }

        $on = $this->showOn($item);

        $event = $this->attempt(fn () => $this->caldav->writer($calendar->account)->create($calendar, [
            'title' => $this->title($item),
            'start_at' => $on->startOfDay(),
            'end_at' => $on->endOfDay(),
            'all_day' => true,
            'notes' => $item->notes,
        ]));

        if ($event) {
            $item->forceFill(['mirror_event_id' => $event->id])->saveQuietly();
        }
    }

    protected function move(ChecklistItem $item, Event $event): void
    {
        $on = $this->showOn($item);
        $title = $this->title($item);

        // iCloud writes are conditional and cost a round trip; a rename or a
        // date change is worth one, a re-render is not.
        if ($event->title === $title && $event->start_at?->isSameDay($on->startOfDay())) {
            return;
        }

        $this->attempt(fn () => $this->caldav->writer($event->calendar->account)->update($event, [
            'title' => $title,
            'start_at' => $on->startOfDay(),
            'end_at' => $on->endOfDay(),
            'all_day' => true,
        ]));
    }

    /**
     * The reminder lands on the surface date, not the due date.
     *
     * A reminder that arrives on the deadline is not a reminder. The surface
     * date is already "the day this starts mattering", so it is the right day
     * for the calendar too.
     */
    protected function showOn(ChecklistItem $item): CarbonImmutable
    {
        return $item->surfaceFrom() ?? $item->due_on->toImmutable()->startOfDay();
    }

    protected function title(ChecklistItem $item): string
    {
        return 'Reminder: '.$item->title.' (due '.$item->due_on->format('j M').')';
    }

    protected function household(ChecklistItem $item): Household
    {
        return $item->checklist->household;
    }

    /**
     * A calendar that will not take the write must not break the to-do.
     *
     * The to-do itself is already saved and shown on the wall; a failed
     * mirror is a missing convenience, not lost data, so it is logged and the
     * next change tries again.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    protected function attempt(callable $work): mixed
    {
        try {
            return $work();
        } catch (CalDavException $e) {
            Log::warning('Deadline reminder could not be written to iCloud', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
