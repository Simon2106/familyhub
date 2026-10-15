<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Activity\ActivityFeed;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Console\Command;

/**
 * The activity feed's fallback.
 *
 * Where the websocket listener is running it feeds the recorder directly
 * and this finds nothing to do, which is the intended state. Where it is
 * not — no Reverb, a daemon that died, a household that never set one up —
 * this is what keeps the feed alive. It will miss a door that opened and
 * shut between two reads, and that is an acceptable thing to miss when the
 * alternative is nothing at all.
 */
class PollActivity extends Command
{
    protected $signature = 'familyhub:poll-activity';

    protected $description = 'Notice what the watched Home Assistant entities have been doing';

    public function handle(ActivityRecorder $recorder, ActivityFeed $feed): int
    {
        $household = Household::current();

        $written = $recorder->poll($household);

        if ($written > 0) {
            $this->components->info(trans_choice(
                '{1}Noted one thing.|[2,*]Noted :count things.',
                $written,
                ['count' => $written],
            ));
        }

        return self::SUCCESS;
    }
}
