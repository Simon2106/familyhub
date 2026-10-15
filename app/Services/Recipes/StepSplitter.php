<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use Illuminate\Support\Str;

/**
 * Turning a wall of text into steps somebody can cook from.
 *
 * Half of what lands in the recipe box is a photograph of a page or an
 * Instagram caption, and neither arrives as a numbered list. Cook mode shows
 * one step a screen, so a recipe with no steps is a recipe cook mode cannot
 * open at all.
 *
 * Split locally first. Numbered lines and sentences get a long way, cost
 * nothing, and work with no API key — and the result is written back to the
 * row, so a recipe is only ever worked out once.
 */
class StepSplitter
{
    /** Below this a "step" is a fragment, not an instruction. */
    public const MIN_LENGTH = 12;

    /** Nobody follows a recipe with more steps than this; it is prose. */
    public const MAX_STEPS = 40;

    /**
     * Steps for a recipe, deriving and storing them if it has none.
     *
     * @return list<string>
     */
    public function for(Recipe $recipe): array
    {
        $existing = $this->normalise((array) ($recipe->steps ?? []));

        if ($existing !== []) {
            return $existing;
        }

        $derived = $this->split($this->sourceText($recipe));

        if ($derived === []) {
            return [];
        }

        // Written back, so the splitting happens once per recipe rather than
        // once per time anybody cooks it.
        $recipe->forceFill(['steps' => $derived])->save();

        return $derived;
    }

    /** Whatever words the recipe has, in the order worth trying them. */
    protected function sourceText(Recipe $recipe): string
    {
        foreach ([$recipe->raw_text, $recipe->notes, $recipe->source_note] as $text) {
            if (filled($text)) {
                return (string) $text;
            }
        }

        return '';
    }

    /**
     * The split itself.
     *
     * Numbered or bulleted lines win outright where they exist — somebody
     * has already done the work — and sentences are the fallback.
     *
     * @return list<string>
     */
    public function split(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $numbered = $this->numberedLines($text);

        if (count($numbered) >= 2) {
            return array_slice($numbered, 0, self::MAX_STEPS);
        }

        $lines = $this->plainLines($text);

        if (count($lines) >= 3) {
            return array_slice($lines, 0, self::MAX_STEPS);
        }

        return array_slice($this->sentences($text), 0, self::MAX_STEPS);
    }

    /** @return list<string> */
    protected function numberedLines(string $text): array
    {
        $found = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            // "1." "1)" "Step 1:" "- " "• " — every way anybody writes one.
            if (preg_match('/^\s*(?:step\s*)?(?:\d{1,2}\s*[.):]|[-*•–])\s*(.+)$/iu', $line, $found_line)) {
                $step = $this->tidy($found_line[1]);

                if ($step !== null) {
                    $found[] = $step;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    protected function plainLines(string $text): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => $this->tidy($line),
            preg_split('/\R+/', $text) ?: [],
        )));
    }

    /** @return list<string> */
    protected function sentences(string $text): array
    {
        // A full stop followed by a capital. Abbreviations are rare in a
        // method and a stray split is a step somebody reads twice, which is
        // a far cheaper mistake than no steps at all.
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/u', str_replace("\n", ' ', $text)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part) => $this->tidy($part),
            $parts,
        )));
    }

    protected function tidy(string $step): ?string
    {
        $step = trim(preg_replace('/\s+/u', ' ', $step) ?? '');

        if (mb_strlen($step) < self::MIN_LENGTH) {
            return null;
        }

        return Str::limit($step, 600);
    }

    /**
     * Steps as a flat list of strings, whichever shape they were stored in.
     *
     * @param  array<int, mixed>  $steps
     * @return list<string>
     */
    public function normalise(array $steps): array
    {
        return array_values(array_filter(array_map(
            function ($step): ?string {
                $text = is_string($step) ? $step : (string) ($step['text'] ?? '');

                $text = trim($text);

                return $text === '' ? null : $text;
            },
            $steps,
        )));
    }
}
