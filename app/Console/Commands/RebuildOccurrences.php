<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Calendar\OccurrenceStore;
use Illuminate\Console\Command;

/**
 * Roll the occurrence window forward.
 *
 * Occurrences are written at sync time, which covers everything that changes.
 * What it does not cover is time passing: a series expanded to eighteen months
 * out in January is eighteen months short of that by February. Nightly, in the
 * small hours, so the far end of next year fills in without anybody noticing.
 *
 * Also the way to repair the table by hand if it is ever doubted.
 */
class RebuildOccurrences extends Command
{
    protected $signature = 'familyhub:rebuild-occurrences';

    protected $description = 'Expand repeating events into the occurrence window';

    public function handle(OccurrenceStore $store): int
    {
        $written = $store->rebuildHousehold(Household::current());

        $this->components->info("{$written} occurrences.");

        return self::SUCCESS;
    }
}
