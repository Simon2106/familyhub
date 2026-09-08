<?php

namespace App\Console\Commands;

use App\Models\ChecklistItem;
use App\Models\Household;
use App\Services\Speech\SpokenQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDoneTodosCommand extends Command
{
    protected $signature = 'familyhub:prune-done {--dry-run : Report what would go without deleting}';

    protected $description = 'Delete completed checklist items past the retention window, and any spoken answers left behind';

    public function handle(): int
    {
        $household = Household::current();
        $days = $household->doneRetentionDays();
        $cutoff = $household->nowLocal()->subDays($days);

        $query = ChecklistItem::query()
            ->whereHas('checklist', fn ($q) => $q->where('household_id', $household->id))
            ->where('is_done', true)
            // An item ticked before done_at existed has no age to judge, so it
            // is left alone rather than deleted on a guess.
            ->whereNotNull('done_at')
            ->where('done_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$count} completed items are older than {$days} days.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->components->info($count === 0
            ? "Nothing to prune; keeping completed items for {$days} days."
            : "Pruned {$count} completed items older than {$days} days.");

        $this->pruneSpeech();

        return self::SUCCESS;
    }

    /**
     * Spoken answers nobody came back for.
     *
     * A question the wall answered aloud leaves an MP3 behind, and one asked
     * as the family walked out of the kitchen leaves it for good. The question
     * itself expires from the cache on its own; the file has to be swept.
     */
    protected function pruneSpeech(): void
    {
        $disk = Storage::disk(SpokenQuestion::DISK);
        $cutoff = now()->subHour()->timestamp;
        $gone = 0;

        foreach ($disk->files(SpokenQuestion::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $gone++;
            }
        }

        if ($gone > 0) {
            $this->components->info("Deleted {$gone} spoken answers nobody came back for.");
        }
    }
}
