<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Activity\ActivityFeed;
use App\Services\Timers\TimerBoard;
use Illuminate\Console\Command;

/**
 * Forgetting what the house did a month ago, and yesterday's timers.
 *
 * The feed is "what happened this afternoon", not an archive of the house,
 * and a dismissed timer is of no interest by the morning.
 */
class PruneActivity extends Command
{
    protected $signature = 'familyhub:prune-activity';

    protected $description = 'Forget old activity and dismissed timers';

    public function handle(ActivityFeed $feed, TimerBoard $timers): int
    {
        $events = $feed->prune();
        $stale = $timers->sweep(Household::current());

        $this->components->info("Forgot {$events} events and {$stale} timers.");

        return self::SUCCESS;
    }
}
