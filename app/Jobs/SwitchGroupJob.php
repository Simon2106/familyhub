<?php

namespace App\Jobs;

use App\Models\SwitchGroup;
use App\Services\HomeAssistant\SwitchBoard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The other end of "off in five minutes".
 *
 * On the server rather than in the browser, so the lamps go off whether or not
 * anybody is still looking at the wall — and whether or not the wall is even
 * awake.
 *
 * Carries the deadline it was dispatched for. A countdown that was cancelled,
 * or replaced by a later tap, leaves this job in the queue with nothing to do;
 * comparing deadlines is how it knows.
 */
class SwitchGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [10];

    public int $timeout = 60;

    public function __construct(
        public int $groupId,
        public string $direction,
        public string $firesAt,
    ) {
        $this->onQueue('capture');
    }

    public function handle(SwitchBoard $board): void
    {
        $group = SwitchGroup::with('entities')->find($this->groupId);

        if (! $group) {
            return;
        }

        // Somebody cancelled, or tapped again and started a different
        // countdown. Either way this one is no longer what the household is
        // waiting for.
        if (! $group->hasPending()
            || $group->pending_direction !== $this->direction
            || ! $group->pending_fires_at->equalTo($this->firesAt)) {
            Log::info('A switch countdown had already been called off', ['group' => $this->groupId]);

            return;
        }

        // Not before it is due. A queue that ignores delays — the sync driver,
        // most of all — would otherwise turn "off in five minutes" into "off",
        // which is the one thing a countdown must never do.
        if ($group->pending_fires_at->isFuture()) {
            Log::info('A switch countdown was reached early and left alone', [
                'group' => $this->groupId,
                'due' => $group->pending_fires_at->toIso8601String(),
            ]);

            return;
        }

        $board->apply($group, $this->direction);
    }
}
