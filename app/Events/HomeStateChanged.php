<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Home Assistant changed something.
 *
 * Deliberately carries no payload. The wall already has the state in a local
 * cache the listener keeps warm, so all it needs is permission to look again —
 * and an empty event means nothing about the house travels over a public
 * channel, which is what lets the wall subscribe without a session.
 *
 * ShouldBroadcastNow rather than ShouldBroadcast: this is dispatched from the
 * listener, which is not a queue worker, and a nudge that waits for a queue is
 * a nudge that arrives after the poll would have.
 */
class HomeStateChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CHANNEL = 'home-assistant';

    /**
     * Tell the wall, and never mind if we cannot.
     *
     * This is a nudge, not part of anything. ShouldBroadcastNow means it goes
     * out inside whatever called it, so a broadcaster that is misconfigured or
     * simply not running throws straight into that caller — which turned a tap
     * on a switch group into a 500 while the lamps had already been switched
     * perfectly well. A lamp must go off whether or not the wall can be told
     * about it.
     *
     * Every dispatch goes through here so that cannot be got wrong again.
     */
    public static function nudge(): void
    {
        try {
            self::dispatch();
        } catch (Throwable $e) {
            Log::warning('Could not tell the wall about a state change', ['error' => $e->getMessage()]);
        }
    }

    public function broadcastOn(): Channel
    {
        return new Channel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'state-changed';
    }
}
