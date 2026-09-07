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
}
