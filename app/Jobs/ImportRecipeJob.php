<?php

namespace App\Jobs;

use App\Models\Recipe;
use App\Services\Recipes\RecipeImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads one saved meal idea into a recipe card.
 *
 * Retries because a recipe site can be briefly unreachable; a card that never
 * succeeds is left as failed with the reason, so it can be retried by hand
 * from the box rather than disappearing.
 */
class ImportRecipeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [20, 90, 300];

    public int $timeout = 300;

    public function __construct(public Recipe $recipe)
    {
        $this->onQueue('capture');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // dontRelease because the default releaseAfter is 0, not null: an
        // overlapping job would be released instantly and burn its attempts.
        return [(new WithoutOverlapping('recipe:'.$this->recipe->id))->dontRelease()->expireAfter(600)];
    }

    public function handle(RecipeImporter $importer): void
    {
        // A recipe someone has already fixed up by hand must not be
        // overwritten by a retry arriving late.
        if ($this->recipe->status === 'ready') {
            return;
        }

        $importer->import($this->recipe);
    }

    public function failed(?Throwable $e): void
    {
        $reason = $e?->getMessage() ?: 'The recipe could not be read.';

        Log::warning('Recipe import failed', ['recipe' => $this->recipe->id, 'error' => $reason]);

        $this->recipe->markFailed($reason);
    }
}
