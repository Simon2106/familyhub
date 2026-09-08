<?php

namespace App\Console\Commands;

use App\Exceptions\IcalException;
use App\Models\Household;
use App\Services\Bins\BinSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the council's bin calendar in.
 *
 * Runs nightly. A council site being down is not an error worth waking anyone
 * for — yesterday's answer is still on the wall and still right.
 */
class SyncBins extends Command
{
    protected $signature = 'familyhub:sync-bins';

    protected $description = 'Refresh bin collection dates from the council calendar';

    public function handle(BinSchedule $bins): int
    {
        $household = Household::query()->orderBy('id')->first();

        if (! $household) {
            $this->warn('No household yet.');

            return self::SUCCESS;
        }

        if ($household->binSource() === 'none') {
            $this->line('No bin calendar set in /admin. Nothing to do.');

            return self::SUCCESS;
        }

        try {
            $count = $bins->sync($household);
        } catch (IcalException $e) {
            // Deliberately not a failure exit: a nightly job that turns red
            // because a council website was rebooting teaches everyone to
            // ignore it.
            Log::warning('Bin calendar could not be refreshed', ['error' => $e->getMessage()]);
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $this->info("Read {$count} collections.");

        return self::SUCCESS;
    }
}
