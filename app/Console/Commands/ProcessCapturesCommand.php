<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\Household;
use Illuminate\Console\Command;

class ProcessCapturesCommand extends Command
{
    protected $signature = 'capture:process
                            {--capture= : Process only this capture id}
                            {--retry-failed : Include captures that previously failed}
                            {--now : Run inline instead of queueing}';

    protected $description = 'Extract items from captures waiting to be read';

    public function handle(): int
    {
        $statuses = $this->option('retry-failed') ? ['pending', 'failed'] : ['pending'];

        $captures = Capture::query()
            ->where('household_id', Household::current()->id)
            ->when($this->option('capture'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereIn('status', $statuses))
            ->orderBy('id')
            ->get();

        if ($captures->isEmpty()) {
            $this->components->info('Nothing waiting to be read.');

            return self::SUCCESS;
        }

        foreach ($captures as $capture) {
            if ($this->option('now')) {
                dispatch_sync(new ProcessCaptureJob($capture));
                $this->components->twoColumnDetail($capture->label(), '<fg=green>read</>');
            } else {
                ProcessCaptureJob::dispatch($capture);
                $this->components->twoColumnDetail($capture->label(), '<fg=gray>queued</>');
            }
        }

        return self::SUCCESS;
    }
}
