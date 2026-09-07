<?php

namespace App\Services\Capture;

/**
 * What one extraction call read, and what it found.
 *
 * Kept per call rather than flattened immediately, so the review inbox can say
 * which document an item came from and show what the model made of it.
 */
class SourceResult
{
    /** @param list<ExtractedItem> $items */
    public function __construct(
        public readonly string $label,
        public readonly string $kind,
        public readonly array $items = [],
        public readonly ?string $summary = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}

    public function isAttachment(): bool
    {
        return $this->kind === 'attachment';
    }
}
