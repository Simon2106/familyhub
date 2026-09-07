<?php

namespace App\Services\Capture;

/**
 * The attachment blocks for one request, plus anything that would not fit.
 *
 * Skipped files are carried rather than dropped: an attachment silently
 * omitted turns into "nothing found", which is indistinguishable from an email
 * that genuinely had no dates in it.
 */
class PreparedAttachments
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $skipped  filenames that could not be sent
     * @param  list<string>  $names    filenames matching $blocks, for logging
     */
    public function __construct(
        public readonly array $blocks = [],
        public readonly array $skipped = [],
        public readonly array $names = [],
    ) {}

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }

    public function skippedSentence(): ?string
    {
        if (! $this->hasSkipped()) {
            return null;
        }

        $count = count($this->skipped);

        return sprintf(
            '%d attachment%s could not be read because %s too large: %s.',
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'it was' : 'they were',
            implode(', ', $this->skipped),
        );
    }
}
