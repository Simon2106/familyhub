<?php

namespace App\Services\Recipes;

use App\Jobs\ImportRecipeJob;
use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The one way a meal idea enters the box, whatever it came from.
 *
 * A row is written straight away with status pending, so the box can show a
 * card that says it is being read — the same pattern as the review inbox, and
 * the reason nothing shared ever disappears into a queue.
 */
class RecipeIntake
{
    public function fromUrl(string $url, ?string $sharedText = null, ?Household $household = null): Recipe
    {
        return $this->start([
            'household_id' => ($household ?? Household::current())->id,
            'title' => $this->provisionalTitle($url),
            'source_kind' => $sharedText === null ? 'url' : 'share',
            'source_url' => mb_substr($url, 0, 2000),
            'raw_text' => $this->clean($sharedText),
        ]);
    }

    public function fromText(string $text, ?Household $household = null): Recipe
    {
        $text = $this->clean($text);

        // Someone pasting a wall of text often pastes a link with it.
        $url = preg_match('#https?://\S+#i', (string) $text, $m) ? rtrim($m[0], '.,)') : null;

        return $this->start([
            'household_id' => ($household ?? Household::current())->id,
            'title' => Str::limit(Str::before((string) $text, "\n") ?: 'Saved idea', 60),
            'source_kind' => 'text',
            'source_url' => $url,
            'raw_text' => $text,
        ]);
    }

    public function fromPhoto(UploadedFile $file, ?string $text = null, ?Household $household = null): Recipe
    {
        $household ??= Household::current();
        $disk = config('filesystems.default');

        return $this->start([
            'household_id' => $household->id,
            'title' => 'Photographed recipe',
            'source_kind' => 'photo',
            'image_disk' => $disk,
            'image_path' => $file->store('recipes/'.$household->id, $disk),
            'raw_text' => $this->clean($text),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function start(array $attributes, bool $dispatch = true): Recipe
    {
        $recipe = Recipe::create($attributes + ['status' => 'pending']);

        if ($dispatch) {
            ImportRecipeJob::dispatch($recipe);
        }

        return $recipe;
    }

    /** A name for the card to wear while the model is still reading. */
    protected function provisionalTitle(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? 'Idea from '.Str::of($host)->replaceStart('www.', '') : 'Saved link';
    }

    protected function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === null || $text === '' ? null : mb_substr($text, 0, 100_000);
    }
}
