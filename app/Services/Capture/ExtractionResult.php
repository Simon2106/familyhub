<?php

namespace App\Services\Capture;

class ExtractionResult
{
    /** @param list<ExtractedItem> $items */
    public function __construct(
        public readonly array $items = [],
        public readonly ?string $summary = null,
    ) {}

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
