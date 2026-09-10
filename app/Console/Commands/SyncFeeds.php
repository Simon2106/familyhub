<?php

namespace App\Console\Commands;

use App\Models\CalendarAccount;
use App\Models\Household;
use App\Services\Ical\FeedSubscription;
use Illuminate\Console\Command;

/**
 * Re-read the calendars the household subscribes to.
 *
 * Runs often and does almost nothing: each subscription carries its own
 * interval, and one that is not due is skipped without a request. A fixtures
 * list changes when a match is called off, which is a few times a season.
 */
class SyncFeeds extends Command
{
    protected $signature = 'familyhub:sync-feeds {--force : Read every feed, whatever its interval}';

    protected $description = 'Re-read subscribed calendars';

    public function handle(FeedSubscription $feeds): int
    {
        $household = Household::current();
        $read = 0;

        $accounts = CalendarAccount::query()
            ->where('household_id', $household->id)
            ->where('provider', CalendarAccount::PROVIDER_ICS)
            ->get();

        foreach ($accounts as $account) {
            if (! $this->option('force') && ! $account->isDueForRefresh()) {
                continue;
            }

            $read += $feeds->refresh($account) > 0 ? 1 : 0;
        }

        if ($read > 0) {
            $this->components->info("{$read} subscribed calendars re-read.");
        }

        return self::SUCCESS;
    }
}
