<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Services\Capture\AttachmentPreparer;
use App\Services\Capture\UrlFetcher;
use App\Services\Recipes\Contracts\RecipeReader;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Gathers everything known about a saved idea and turns it into a card.
 *
 * The interesting case is the one the family will hit most: a recipe shared
 * out of Instagram or TikTok, where the link goes to a login wall and the only
 * real content is the caption that came with it. That is not an error — it is
 * a thinner recipe, and the card says so.
 */
class RecipeImporter
{
    /** Below this, a fetched page is a cookie banner or a login wall, not a recipe. */
    protected const THIN_PAGE_CHARS = 400;

    public function __construct(
        protected RecipeReader $reader,
        protected UrlFetcher $urls,
        protected AttachmentPreparer $media,
    ) {}

    public function import(Recipe $recipe): Recipe
    {
        [$content, $note, $heroImage] = $this->gather($recipe);

        if ($content === []) {
            throw new RuntimeException('There was nothing readable in that.');
        }

        $result = $this->reader->read($content);

        $recipe->forceFill($result->toAttributes() + [
            'status' => 'ready',
            'error' => null,
            // The page's own preview image beats one the model spotted in the
            // text, which is often a logo or an advert.
            'hero_image_url' => $recipe->image_path ? null : ($heroImage ?? $result->imageUrl),
            'source_note' => $note ?? $result->note,
        ])->save();

        return $recipe;
    }

    /**
     * Everything worth sending, plus anything the card should admit to.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string, 2: ?string}
     */
    protected function gather(Recipe $recipe): array
    {
        $blocks = [];
        $note = null;
        $heroImage = null;
        $text = trim((string) $recipe->raw_text);

        if ($image = $this->photo($recipe)) {
            $blocks[] = $image;
        }

        if ($recipe->source_url) {
            [$page, $note] = $this->page($recipe, $text);

            if ($page !== null) {
                $heroImage = $page['image'] ?? null;
                $text = trim($text."\n\n".$page['text']);
            }
        }

        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $this->instruction($recipe, $text)];
        } elseif ($blocks !== []) {
            $blocks[] = ['type' => 'text', 'text' => $this->instruction($recipe, null)];
        }

        return [$blocks, $note, $heroImage];
    }

    /**
     * The page behind the link, or an honest note about why there isn't one.
     *
     * @return array{0: array{title: ?string, text: string, image: ?string}|null, 1: ?string}
     */
    protected function page(Recipe $recipe, string $sharedText): array
    {
        try {
            $page = $this->urls->fetch($recipe->source_url);
        } catch (Throwable $e) {
            return [null, $this->fellBack($recipe, $sharedText, 'could not be opened')];
        }

        // Instagram and TikTok answer a logged-out request with a shell of a
        // page. It fetches fine and contains nothing, which is worse than a
        // failure because it looks like success.
        if (mb_strlen(trim($page['text'])) < self::THIN_PAGE_CHARS && $sharedText !== '') {
            return [
                ['title' => $page['title'], 'text' => '', 'image' => $page['image']],
                $this->fellBack($recipe, $sharedText, 'needed a login'),
            ];
        }

        return [$page, null];
    }

    /** Says on the card what we were reduced to working from. */
    protected function fellBack(Recipe $recipe, string $sharedText, string $why): string
    {
        $host = parse_url($recipe->source_url, PHP_URL_HOST) ?: 'That link';

        if ($sharedText === '') {
            throw new RuntimeException(
                "The link to {$host} {$why}, and there was no text saved with it to fall back on."
            );
        }

        return ucfirst($host)." {$why}, so this is from the text you shared.";
    }

    /** @return array<string, mixed>|null */
    protected function photo(Recipe $recipe): ?array
    {
        if (! $recipe->image_path) {
            return null;
        }

        $disk = Storage::disk($recipe->image_disk ?? config('filesystems.default'));

        if (! $disk->exists($recipe->image_path)) {
            return null;
        }

        return $this->media->blockFor($disk->get($recipe->image_path), $disk->mimeType($recipe->image_path) ?: 'image/jpeg');
    }

    /** The volatile half of the prompt: what this particular thing is. */
    protected function instruction(Recipe $recipe, ?string $text): string
    {
        $lines = ['Save this as a recipe card.'];

        if ($recipe->source_url) {
            $lines[] = 'It was saved from: '.$recipe->source_url;
        }

        if ($recipe->source_kind === 'photo') {
            $lines[] = 'The picture is a photograph of a recipe — a cookbook page, a card, or a handwritten note. Read it.';
        }

        if ($text !== null) {
            $lines[] = "\nHere is what was saved:\n\n".$text;
        }

        return implode("\n", $lines);
    }
}
