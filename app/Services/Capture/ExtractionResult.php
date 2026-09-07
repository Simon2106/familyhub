<?php

namespace App\Services\Capture;

class ExtractionResult
{
    /**
     * @param  list<ExtractedItem>  $items
     * @param  list<SourceResult>  $sources
     */
    public function __construct(
        public readonly array $items = [],
        public readonly ?string $summary = null,
        public readonly array $sources = [],
    ) {}

    /**
     * Fold the per-source results into one reviewable set, keeping the sources
     * themselves so the card can show where each item came from.
     *
     * @param  list<SourceResult>  $sources
     */
    public static function fromSources(array $sources): self
    {
        $merged = self::merge(array_map(
            fn (SourceResult $source) => new self(
                array_map(fn (ExtractedItem $item) => $item->from($source->label), $source->items),
                $source->summary,
            ),
            $sources,
        ));

        return new self($merged->items, $merged->summary, $sources);
    }

    public function withSummary(?string $summary): self
    {
        return new self($this->items, $summary, $this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Combine the results of several calls over one capture.
     *
     * Splitting a capture per attachment means the same event can come back
     * from the covering email and from the PDF it refers to. That duplicate is
     * an artefact of how the work was divided, not something the household
     * should have to reject twice, so identical items are folded together and
     * the better-described one kept.
     *
     * @param  list<ExtractionResult>  $results
     */
    public static function merge(array $results): self
    {
        /** @var array<string, ExtractedItem> $byKey */
        $byKey = [];
        $summaries = [];

        foreach ($results as $result) {
            foreach ($result->items as $item) {
                $key = self::identity($item);
                $existing = $byKey[$key] ?? null;

                $byKey[$key] = $existing === null ? $item : self::better($existing, $item);
            }

            if (filled($result->summary)) {
                $summaries[] = trim($result->summary);
            }
        }

        return new self(
            array_values($byKey),
            $summaries === [] ? null : implode(' ', array_unique($summaries)),
        );
    }

    /** Same title on the same date is the same thing, however it was found. */
    protected static function identity(ExtractedItem $item): string
    {
        $title = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item->title) ?? $item->title));

        return $title.'|'.($item->startAt?->toDateTimeString() ?? 'undated');
    }

    /**
     * Of two readings of the same item, keep the one a person can act on:
     * more confident first, then whichever carries more detail.
     */
    protected static function better(ExtractedItem $a, ExtractedItem $b): ExtractedItem
    {
        if ($a->confidence !== $b->confidence) {
            return $a->confidence > $b->confidence ? $a : $b;
        }

        return self::detail($b) > self::detail($a) ? $b : $a;
    }

    protected static function detail(ExtractedItem $item): int
    {
        return (int) filled($item->location)
            + (int) filled($item->notes)
            + (int) filled($item->memberHint)
            + (int) ($item->endAt !== null);
    }
}
