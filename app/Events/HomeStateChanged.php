<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

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

    public function broadcastOn(): Channel
    {
        return new Channel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'state-changed';
    }
}
