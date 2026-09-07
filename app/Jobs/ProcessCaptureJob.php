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

    /**
     * Extraction over a term calendar is not quick, and a capture with several
     * attachments is now several sequential calls rather than one.
     */
    public int $timeout = 900;

    public function __construct(public Capture $capture)
    {
        $this->onQueue('capture');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // dontRelease: releaseAfter defaults to 0, not null, so a job that
        // cannot get the lock is re-released immediately and burns an attempt
        // each time until it exceeds tries. Dropping it is right here — the
        // job already holding the lock is doing the same work.
        return [(new WithoutOverlapping('capture-'.$this->capture->id))->dontRelease()->expireAfter(1200)];
    }

    public function handle(ItemExtractor $extractor): void
    {
        // Only skip a capture that already produced a result: a duplicate
        // delivery must not double the items in the inbox.
        //
        // Deliberately NOT skipping 'processing'. A worker leaves that status
        // behind between attempts, so treating it as "someone else has this"
        // made every retry return early — which counts as success, so failed()
        // never ran and the capture sat on "Reading it…" for good. Concurrency
        // is handled by WithoutOverlapping, not by this check.
        if (in_array($this->capture->status, ['reviewing', 'done'], strict: true)) {
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
