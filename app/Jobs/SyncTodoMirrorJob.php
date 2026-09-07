<?php

namespace App\Jobs;

use App\Models\ChecklistItem;
use App\Services\Todos\DeadlineMirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pushes a to-do's optional iCloud reminder into line, off the request.
 *
 * Ticking a to-do on the wall must feel instant, and a CalDAV round trip does
 * not. Ids rather than the model itself, because this also runs for a to-do
 * that has just been deleted — there is nothing left to serialise, only a
 * reminder left behind to clear up.
 */
class SyncTodoMirrorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public ?int $itemId, public ?int $mirrorEventId = null)
    {
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Two edits in quick succession must not race to create two reminders.
        return [(new WithoutOverlapping('todo-mirror:'.($this->itemId ?? 'orphan-'.$this->mirrorEventId)))
            ->dontRelease()
            ->expireAfter(300)];
    }

    public function handle(DeadlineMirror $mirror): void
    {
        $item = $this->itemId
            ? ChecklistItem::with(['checklist.household', 'mirrorEvent.calendar.account'])->find($this->itemId)
            : null;

        // Gone means the reminder is orphaned and should go with it.
        $item ? $mirror->sync($item) : $mirror->forget($this->mirrorEventId);
    }
}
