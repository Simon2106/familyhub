<?php

namespace App\Observers;

use App\Jobs\SyncTodoMirrorJob;
use App\Models\ChecklistItem;

/**
 * Keeps the optional iCloud reminder in step wherever a to-do is changed.
 *
 * There are several places a to-do can be ticked or re-dated — the wall panel,
 * the Lists tab, a phone, an accepted capture — and each one forgetting to
 * update the mirror would leave a stale reminder on everyone's calendar. This
 * is the one place that cannot be forgotten.
 */
class ChecklistItemObserver
{
    /** The fields a mirrored reminder is built from. */
    protected const MIRRORED = ['title', 'due_on', 'surface_from', 'is_done', 'notes'];

    public function created(ChecklistItem $item): void
    {
        if ($item->due_on !== null) {
            SyncTodoMirrorJob::dispatch($item->id);
        }
    }

    public function updated(ChecklistItem $item): void
    {
        // saveQuietly() in the mirror itself keeps this from recursing, but a
        // to-do re-saved with nothing relevant changed should not cost a
        // round trip either.
        if ($item->wasChanged(self::MIRRORED)) {
            SyncTodoMirrorJob::dispatch($item->id);
        }
    }

    /**
     * Take the reminder's id from the row, not from this copy of the model.
     *
     * The id is written by the job, on its own freshly-loaded instance, so
     * whichever copy the caller happens to be holding usually still thinks
     * there is no reminder — and the reminder would outlive the to-do.
     */
    public function deleting(ChecklistItem $item): void
    {
        $item->mirror_event_id = ChecklistItem::whereKey($item->getKey())->value('mirror_event_id');
    }

    public function deleted(ChecklistItem $item): void
    {
        if ($item->mirror_event_id !== null) {
            SyncTodoMirrorJob::dispatch(null, $item->mirror_event_id);
        }
    }
}
