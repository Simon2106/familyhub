<?php

namespace App\Jobs;

use App\Models\Capture;
use App\Services\Capture\Contracts\ItemExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts items from one capture.
 *
 * Retries because the API can be briefly unavailable; the capture is left in
 * "failed" with the reason if it never succeeds, so it can be retried by hand
 * rather than disappearing.
 */
class ProcessCaptureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 180, 600];

    /** Extraction over a term calendar is not quick. */
    public int $timeout = 300;

    public function __construct(public Capture $capture)
    {
        $this->onQueue('capture');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('capture-'.$this->capture->id))->expireAfter(600)];
    }

    public function handle(ItemExtractor $extractor): void
    {
        // Already reviewed or in flight; a duplicate delivery must not double
        // the items in the inbox.
        if (! in_array($this->capture->status, ['pending', 'failed'], strict: true)) {
            return;
        }

        $this->capture->markProcessing();

        $result = $extractor->extract($this->capture->load(['attachments', 'household.members']));

        foreach ($result->items as $item) {
            $this->capture->items()->create($item->toAttributes());
        }

        $this->capture->markReviewed($result->summary, count($result->items));

        Log::info('Capture processed', [
            'capture' => $this->capture->id,
            'source' => $this->capture->source,
            'items' => count($result->items),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->capture->markFailed($e->getMessage());
    }
}
