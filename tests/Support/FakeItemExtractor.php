<?php

namespace Tests\Support;

use App\Models\Capture;
use App\Services\Capture\Contracts\ItemExtractor;
use App\Services\Capture\ExtractionParser;
use App\Services\Capture\ExtractionResult;
use RuntimeException;

/**
 * Stands in for Claude.
 *
 * Queued responses are given as the same JSON shape the model returns, so the
 * parser under test is the real one — only the network call is faked.
 */
class FakeItemExtractor implements ItemExtractor
{
    /** @var list<array<string, mixed>> */
    public array $responses = [];

    /** @var list<Capture> */
    public array $sawCaptures = [];

    public ?string $throw = null;

    /** @param array<string, mixed> $response */
    public function queue(array $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    /** Convenience for the common case of a single event. */
    public function queueItems(array $items, string $summary = 'A test capture.'): self
    {
        return $this->queue(['items' => $items, 'summary' => $summary]);
    }

    public function extract(Capture $capture): ExtractionResult
    {
        $this->sawCaptures[] = $capture;

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        $response = array_shift($this->responses) ?? ['items' => [], 'summary' => 'Nothing found.'];

        return ExtractionParser::fromArray($response);
    }
}
