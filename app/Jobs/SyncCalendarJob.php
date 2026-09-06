<?php

namespace App\Jobs;

use App\Models\Calendar;
use App\Services\CalDav\AccountService;
use App\Services\CalDav\CalDavManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one calendar's events. Kept separate from the account job so a single
 * broken calendar cannot stall the rest of the household's sync.
 */
class SyncCalendarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public Calendar $calendar,
        public bool $force = false,
    ) {
        $this->onQueue('sync');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('caldav-calendar-'.$this->calendar->id))->expireAfter(600)];
    }

    public function handle(CalDavManager $manager): void
    {
        $result = $manager->sync($this->calendar->account)->sync($this->calendar, $this->force);

        Log::info('Synced calendar', [
            'calendar' => $this->calendar->name,
            'account' => $this->calendar->account->label,
            'result' => $result->summary(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        app(AccountService::class)->recordFailure($this->calendar->account, $e);
    }
}
