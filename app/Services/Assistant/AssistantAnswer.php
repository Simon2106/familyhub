<?php

namespace App\Services\Assistant;

/** What came back: the answer, and what it was worked out from. */
class AssistantAnswer
{
    /** @param list<string> $used the tools consulted, in the order they ran */
    public function __construct(
        public readonly string $text,
        public readonly array $used = [],
    ) {}

    public function usedNothing(): bool
    {
        return $this->used === [];
    }
}
