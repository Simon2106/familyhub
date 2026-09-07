<?php

namespace App\Console\Commands;

use App\Models\ChecklistItem;
use App\Models\Household;
use Illuminate\Console\Command;

class PruneDoneTodosCommand extends Command
{
    protected $signature = 'familyhub:prune-done {--dry-run : Report what would go without deleting}';

    protected $description = 'Delete completed checklist items older than the household retention window';

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

        return self::SUCCESS;
    }
}
