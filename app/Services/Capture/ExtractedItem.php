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
        /** The event this deadline belongs to, named rather than referenced. */
        public readonly ?string $forEventTitle = null,
        /** The passage this was read from, so a reviewer can check it. */
        public readonly ?string $excerpt = null,
        public readonly ?int $page = null,
        public readonly int $confidence = 0,
        /** Which document this was read from, for the review card. */
        public readonly ?string $sourceLabel = null,
    ) {}

    /** The same item, tagged with the document it came from. */
    public function from(string $label): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startAt: $this->startAt,
            endAt: $this->endAt,
            allDay: $this->allDay,
            location: $this->location,
            notes: $this->notes,
            memberHint: $this->memberHint,
            forEventTitle: $this->forEventTitle,
            excerpt: $this->excerpt,
            page: $this->page,
            confidence: $this->confidence,
            sourceLabel: $label,
        );
    }

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
            'for_event_title' => $this->forEventTitle,
            'excerpt' => $this->excerpt,
            'source_page' => $this->page,
            'confidence' => $this->confidence,
        ];
    }
}
