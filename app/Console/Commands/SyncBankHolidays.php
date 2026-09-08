<?php

namespace App\Console\Commands;

use App\Services\Schools\BankHolidays;
use Illuminate\Console\Command;

/**
 * Refreshes the bank holidays from GOV.UK.
 *
 * Monthly is plenty: the list changes when a monarch dies or a jubilee is
 * announced, and a month's notice of either is more than enough for a wall
 * calendar.
 */
class SyncBankHolidays extends Command
{
    protected $signature = 'familyhub:sync-bank-holidays';

    protected $description = 'Refresh England & Wales bank holidays from GOV.UK';

    public function handle(BankHolidays $holidays): int
    {
        $known = $holidays->sync();

        $this->info("{$known} bank holidays known.");

        return self::SUCCESS;
    }
}
