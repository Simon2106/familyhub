<?php

namespace App\Services\Search;

use Carbon\CarbonImmutable;

/** One thing found, and how to get to it. */
class SearchResult
{
    public function __construct(
        /** event | todo | shopping | chore | routine | reward | recipe | meal | capture | member | place */
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $snippet = null,
        public readonly ?CarbonImmutable $date = null,
        public readonly int $score = 0,
        public readonly ?string $url = null,
        /** Set where the thing can be opened without leaving the page. */
        public readonly ?array $opens = null,
    ) {}

    /** The heading this sits under. */
    public const GROUPS = [
        'event' => 'Calendar',
        'todo' => 'To-dos',
        'shopping' => 'Shopping',
        'list' => 'Lists',
        'chore' => 'Chores',
        'routine' => 'Routines',
        'meal' => 'Meals',
        'recipe' => 'Recipes',
        'reward' => 'Rewards',
        'capture' => 'Review inbox',
        'member' => 'Family',
        'place' => 'Places',
    ];

    public function group(): string
    {
        return self::GROUPS[$this->type] ?? 'Other';
    }

    /**
     * Which tab of the wall display holds this, if any.
     *
     * The wall must never follow a link: it is a kiosk with no address bar and
     * no way back. So a result there switches tab instead, and anything that
     * only lives in /admin is shown but not offered as somewhere to go.
     */
    public function wallTab(): ?string
    {
        return match ($this->type) {
            'event', 'chore', 'routine' => 'home',
            'todo', 'shopping', 'list' => 'lists',
            'meal', 'recipe' => 'meals',
            'capture' => 'review',
            default => null,
        };
    }

    public function withScore(int $score): self
    {
        return new self(
            $this->type, $this->title, $this->snippet, $this->date,
            $score, $this->url, $this->opens,
        );
    }
}
