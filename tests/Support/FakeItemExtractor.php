<?php

namespace Tests\Support;

use App\Models\Capture;
use App\Services\Capture\Contracts\ItemExtractor;
use App\Services\Capture\ExtractionParser;
use App\Services\Capture\ExtractionResult;
use App\Services\Capture\SourceResult;
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

    /** The label to attribute queued items to, as the real extractor would. */
    public ?string $sourceLabel = null;

    public ?string $sourceKind = null;

    public function fromSource(string $label, string $kind = 'attachment'): self
    {
        $this->sourceLabel = $label;
        $this->sourceKind = $kind;

        return $this;
    }

    public function extract(Capture $capture): ExtractionResult
    {
        $this->sawCaptures[] = $capture;

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        $response = array_shift($this->responses) ?? ['items' => [], 'summary' => 'Nothing found.'];

        $result = ExtractionParser::fromArray($response, $this->sourceLabel);

        if ($this->sourceLabel === null) {
            return $result;
        }

        return ExtractionResult::fromSources([new SourceResult(
            label: $this->sourceLabel,
            kind: $this->sourceKind ?? 'attachment',
            items: $result->items,
            summary: $result->summary,
        )]);
    }
}
