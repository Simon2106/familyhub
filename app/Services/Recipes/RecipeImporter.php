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
            // The dead end the family actually hits: a link shared straight
            // out of Instagram with nothing else. "There was nothing readable
            // in that" told them only that it had failed, above a Try again
            // button that could only ever fail the same way.
            throw new RuntimeException($recipe->source_url
                ? $this->whatToDoInstead(parse_url($recipe->source_url, PHP_URL_HOST) ?: 'That link')
                : 'There was nothing readable in that.');
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

                // The author's own structured version first, where there is
                // one. Reading a page's prose is guesswork next to reading the
                // list the site published for search engines.
                $text = trim(implode("\n\n", array_filter([
                    $text,
                    $this->structured($page['recipe'] ?? null),
                    // On a login-walled page this is the whole post, and it is
                    // the only thing a logged-out fetch comes back with.
                    $this->caption($page),
                    $page['text'],
                ])));
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
     * @return array{0: array{title: ?string, text: string, image: ?string, recipe: ?array<string, mixed>}|null, 1: ?string}
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
        //
        // Length alone cannot tell that apart from a short but perfectly good
        // recipe page — plenty of those come in under four hundred characters
        // — so the walled sites are named. A page carrying a schema.org recipe
        // is never empty however little prose survived stripping it, and
        // neither is one whose caption survived in its meta tags.
        $walled = $this->loginWalled($recipe->source_url);

        $nothingToRead = blank($page['recipe'] ?? null)
            && blank($this->caption($page))
            && ($walled !== null || mb_strlen(trim($page['text'])) < self::THIN_PAGE_CHARS);

        if ($nothingToRead && ($sharedText !== '' || $walled !== null)) {
            return [
                ['title' => $page['title'], 'text' => '', 'image' => $page['image'],
                    'description' => null, 'recipe' => null],
                $this->fellBack($recipe, $sharedText, 'needed a login'),
            ];
        }

        return [$page, null];
    }

    /**
     * A page's own summary, where it is long enough to be worth reading.
     *
     * A one-line SEO blurb is not a recipe and would only mislead the model;
     * an Instagram caption with the method in it very much is.
     *
     * @param  array<string, mixed>  $page
     */
    protected function caption(array $page): ?string
    {
        $description = trim((string) ($page['description'] ?? ''));

        return mb_strlen($description) >= 80 ? $description : null;
    }

    /** Says on the card what we were reduced to working from. */
    protected function fellBack(Recipe $recipe, string $sharedText, string $why): string
    {
        $host = parse_url($recipe->source_url, PHP_URL_HOST) ?: 'That link';

        if ($sharedText === '') {
            throw new RuntimeException($this->whatToDoInstead($host, $why));
        }

        return ucfirst($host)." {$why}, so this is from the text you shared.";
    }

    /**
     * The sites that will never answer a logged-out fetch.
     *
     * Named individually because "needs a login" is guessable advice, and
     * "open the post and copy the caption" is a thing somebody can go and do.
     */
    protected function whatToDoInstead(string $host, string $why = 'gave us nothing readable'): string
    {
        if ($name = $this->loginWalled($host)) {
            return "{$name} only shows this to somebody logged in, so the link on its own "
                .'gives us nothing. Open the post, copy the caption, and add it under Text.';
        }

        return ucfirst($host)." {$why} and there was no text saved with it. "
            .'Paste the recipe under Text instead.';
    }

    /**
     * The sites that will never answer a logged-out fetch, by name.
     *
     * Named individually because "needs a login" is guessable advice and
     * "open the post and copy the caption" is a thing somebody can go and do —
     * and because it is the only reliable way to tell a login wall from a
     * short recipe page.
     */
    protected function loginWalled(string $hostOrUrl): ?string
    {
        $host = str_contains($hostOrUrl, '://')
            ? (parse_url($hostOrUrl, PHP_URL_HOST) ?: '')
            : $hostOrUrl;

        $bare = preg_replace('/^www\./', '', mb_strtolower($host)) ?? $host;

        foreach ([
            'instagram.com' => 'Instagram',
            'tiktok.com' => 'TikTok',
            'facebook.com' => 'Facebook',
            'fb.watch' => 'Facebook',
            'x.com' => 'X',
            'twitter.com' => 'X',
            'threads.net' => 'Threads',
        ] as $domain => $name) {
            if ($bare === $domain || str_ends_with($bare, '.'.$domain)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A schema.org Recipe as plain lines for the model to read.
     *
     * Not used directly as the answer: the site's own list is authoritative
     * about what goes in, but "750g/1lb 10oz cannellini beans (from tins or a
     * jar), drained" still has to become a quantity, a unit and a thing you can
     * find in a supermarket, which is the part the model is good at.
     *
     * @param  array<string, mixed>|null  $recipe
     */
    protected function structured(?array $recipe): ?string
    {
        if (! is_array($recipe)) {
            return null;
        }

        $lines = ['The site publishes this recipe as structured data. Prefer it over the page text:'];

        foreach (['name' => 'Title', 'recipeYield' => 'Serves', 'description' => 'About'] as $key => $label) {
            if (filled($value = $this->flatten($recipe[$key] ?? null))) {
                $lines[] = $label.': '.$value;
            }
        }

        if ($ingredients = $this->lines($recipe['recipeIngredient'] ?? null)) {
            $lines[] = "\nIngredients:\n- ".implode("\n- ", $ingredients);
        }

        if ($steps = $this->lines($recipe['recipeInstructions'] ?? null)) {
            $lines[] = "\nMethod:\n".implode("\n", array_map(
                fn (int $i, string $step) => ($i + 1).'. '.$step,
                array_keys($steps),
                $steps,
            ));
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }

    /**
     * Flatten the several shapes schema.org allows into plain strings.
     *
     * Instructions in particular arrive as strings, as HowToStep objects, or
     * as HowToSections containing more of either.
     *
     * @return list<string>
     */
    protected function lines(mixed $value): array
    {
        if (blank($value)) {
            return [];
        }

        $out = [];

        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $entry) {
            if (is_array($entry) && isset($entry['itemListElement'])) {
                $out = array_merge($out, $this->lines($entry['itemListElement']));

                continue;
            }

            if (filled($text = $this->flatten($entry))) {
                $out[] = $text;
            }
        }

        return $out;
    }

    protected function flatten(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim(html_entity_decode((string) $value));
        }

        if (is_array($value)) {
            // A HowToStep, or a list of them joined for a single field.
            $text = $value['text'] ?? $value['name'] ?? null;

            return $text !== null
                ? $this->flatten($text)
                : trim(implode(', ', array_filter(array_map($this->flatten(...), $value))));
        }

        return null;
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
