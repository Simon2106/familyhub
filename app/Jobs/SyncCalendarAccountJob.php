<?php

namespace App\Jobs;

use App\Models\CalendarAccount;
use App\Services\CalDav\AccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Refreshes one account's calendar list, then fans out a job per calendar.
 */
class SyncCalendarAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public CalendarAccount $account,
        public bool $force = false,
    ) {
        $this->onQueue('sync');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Two overlapping syncs of one account would race on sync tokens.
        // dontRelease: releaseAfter defaults to 0, so an overlapping run is
        // released immediately and burns an attempt, and a slow sync overlapping
        // the five-minute schedule exhausts tries as MaxAttemptsExceededException.
        // The next scheduled run picks it up instead.
        return [(new WithoutOverlapping('caldav-account-'.$this->account->id))->dontRelease()->expireAfter(600)];
    }

    public function handle(AccountService $accounts): void
    {
        if ($this->account->status === 'disabled') {
            return;
        }

        $accounts->refreshCalendars($this->account);

        $this->account->calendars()
            ->where('is_visible', true)
            ->get()
            ->each(fn ($calendar) => SyncCalendarJob::dispatch($calendar, $this->force));

        $accounts->recordSuccess($this->account);
    }

    public function failed(Throwable $e): void
    {
        app(AccountService::class)->recordFailure($this->account, $e);
    }
}
