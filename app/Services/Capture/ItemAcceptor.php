<?php

namespace App\Services\Capture;

use App\Models\Calendar;
use App\Models\CaptureItem;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Services\Attribution\EventAttributor;
use App\Services\CalDav\CalDavManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns an accepted item into something real.
 *
 * Events go to iCloud through the Phase 2 write-back, so a captured event is
 * indistinguishable from one added on a phone. Tasks become to-dos. Nothing is
 * written until someone accepts.
 */
class ItemAcceptor
{
    public function __construct(
        protected CalDavManager $caldav,
        protected EventAttributor $attributor,
    ) {}

    public function accept(CaptureItem $item, ?Calendar $calendar = null, ?int $memberId = null): CaptureItem
    {
        if ($item->status !== 'pending') {
            return $item;
        }

        $memberId ??= $item->member_id;

        match ($item->type) {
            'event' => $this->acceptEvent($item, $calendar ?? $item->calendar ?? $this->defaultCalendar(), $memberId),
            'task' => $this->acceptTask($item, $memberId),
            default => $this->acceptNote($item, $memberId),
        };

        $item->forceFill([
            'status' => 'accepted',
            'member_id' => $memberId,
            'reviewed_at' => now(),
        ])->save();

        $item->capture->closeIfSettled();

        return $item->fresh();
    }

    public function reject(CaptureItem $item): CaptureItem
    {
        $item->forceFill(['status' => 'rejected', 'reviewed_at' => now()])->save();

        $item->capture->closeIfSettled();

        return $item;
    }

    protected function acceptEvent(CaptureItem $item, ?Calendar $calendar, ?int $memberId): void
    {
        if (! $item->isSchedulable()) {
            // Undated "events" are really tasks; accepting one as a to-do beats
            // refusing it or inventing a date.
            $this->acceptTask($item, $memberId);

            return;
        }

        if (! $calendar) {
            throw new RuntimeException('Connect an iCloud calendar before accepting events.');
        }

        $start = $item->start_at;
        $end = $item->end_at ?? ($item->all_day ? $start : $start->addHour());

        $event = $this->caldav->writer($calendar->account)->create($calendar, [
            'title' => $item->title,
            'start_at' => $item->all_day ? $start->startOfDay() : $start,
            'end_at' => $item->all_day ? $start->endOfDay() : $end,
            'all_day' => $item->all_day,
            'location' => $item->location,
            'notes' => $item->notes,
        ]);

        // A reviewer who named a member has said something the title may not,
        // so that choice is pinned rather than left to attribution.
        if ($memberId) {
            $this->attributor->setManually($event, [$memberId]);
        }

        $item->forceFill(['event_id' => $event->id, 'calendar_id' => $calendar->id])->save();

        $this->linkWaitingTasks($item);
    }

    protected function acceptTask(CaptureItem $item, ?int $memberId): void
    {
        $todo = ChecklistItem::create([
            'checklist_id' => Checklist::home($item->capture->household)->id,
            'title' => $item->title,
            // A captured deadline is the due date; when it starts being shown
            // is left to the household's lead time rather than pinned here.
            'due_on' => $item->start_at?->timezone($item->capture->household->displayTimezone())->toDateString(),
            'member_id' => $memberId,
            'notes' => $item->notes,
            'event_id' => $this->relatedEventId($item),
        ]);

        $item->forceFill(['checklist_item_id' => $todo->id])->save();
    }

    /**
     * The event a deadline belongs to, if it has been accepted too.
     *
     * The model names the event rather than pointing at it, so the link is
     * made here by matching that name against the capture's other items. It
     * costs nothing when there is no match, and turns "Complete consent form"
     * into "Complete consent form — for Flu vaccination" when there is.
     */
    protected function relatedEventId(CaptureItem $item): ?int
    {
        if (blank($item->for_event_title)) {
            return null;
        }

        return $this->sibling($item, $item->for_event_title)?->event_id;
    }

    /**
     * Fill in links the other way round.
     *
     * Items are accepted one at a time and in whatever order the reviewer
     * taps, so the event may well arrive after the task that refers to it.
     */
    protected function linkWaitingTasks(CaptureItem $event): void
    {
        CaptureItem::query()
            ->where('capture_id', $event->capture_id)
            ->whereNotNull('checklist_item_id')
            ->whereRaw('LOWER(TRIM(for_event_title)) = ?', [Str::lower(trim($event->title))])
            ->with('checklistItem')
            ->get()
            ->each(fn (CaptureItem $task) => $task->checklistItem
                ?->forceFill(['event_id' => $event->event_id])->save());
    }

    /** Another item from the same capture, found by title. */
    protected function sibling(CaptureItem $item, string $title): ?CaptureItem
    {
        return CaptureItem::query()
            ->where('capture_id', $item->capture_id)
            ->whereKeyNot($item->getKey())
            ->whereNotNull('event_id')
            ->whereRaw('LOWER(TRIM(title)) = ?', [Str::lower(trim($title))])
            ->first();
    }

    /** A note is kept as an undated to-do, which is where a household looks for it. */
    protected function acceptNote(CaptureItem $item, ?int $memberId): void
    {
        $todo = ChecklistItem::create([
            'checklist_id' => Checklist::home($item->capture->household)->id,
            'title' => $item->title,
            'member_id' => $memberId,
            'notes' => $item->notes,
        ]);

        $item->forceFill(['checklist_item_id' => $todo->id])->save();
    }

    /** The first writable calendar, used when the reviewer did not pick one. */
    public function defaultCalendar(?Household $household = null): ?Calendar
    {
        $household ??= Household::current();

        return Calendar::query()
            ->whereHas('account', fn ($q) => $q
                ->where('household_id', $household->id)
                ->where('provider', 'icloud'))
            ->where('is_writable', true)
            ->where('is_visible', true)
            ->orderBy('id')
            ->first();
    }
}
