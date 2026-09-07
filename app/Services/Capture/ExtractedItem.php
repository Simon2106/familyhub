<?php

namespace App\Services\Capture;

use Carbon\CarbonImmutable;

/** One item as the model returned it, before anyone has looked at it. */
class ExtractedItem
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly ?CarbonImmutable $startAt = null,
        public readonly ?CarbonImmutable $endAt = null,
        public readonly bool $allDay = false,
        public readonly ?string $location = null,
        public readonly ?string $notes = null,
        public readonly ?string $memberHint = null,
        public readonly int $confidence = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'all_day' => $this->allDay,
            'location' => $this->location,
            'notes' => $this->notes,
            'member_hint' => $this->memberHint,
            'confidence' => $this->confidence,
        ];
    }
}
