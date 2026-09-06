<?php

namespace App\Console\Commands;

use App\Jobs\SyncCalendarAccountJob;
use App\Models\CalendarAccount;
use Illuminate\Console\Command;

class SyncCalendarsCommand extends Command
{
    protected $signature = 'sync:calendars
                            {--account= : Sync only this account id}
                            {--force : Discard sync tokens and do a full pass}
                            {--now : Run inline instead of queueing}';

    protected $description = 'Sync all connected iCloud calendars';

    public function handle(): int
    {
        $accounts = CalendarAccount::query()
            ->where('provider', CalendarAccount::PROVIDER_ICLOUD)
            ->where('status', '!=', 'disabled')
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->components->warn('No iCloud accounts connected. Add one in /admin.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $job = new SyncCalendarAccountJob($account, (bool) $this->option('force'));

            if ($this->option('now')) {
                dispatch_sync($job);
                $this->components->twoColumnDetail($account->label, '<fg=green>synced</>');
            } else {
                dispatch($job);
                $this->components->twoColumnDetail($account->label, '<fg=gray>queued</>');
            }
        }

        return self::SUCCESS;
    }
}
