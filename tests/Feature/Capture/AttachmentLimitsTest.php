<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\CaptureAttachment;
use App\Models\Household;
use App\Services\Capture\AttachmentPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A real inbound email with two PDFs is the case these limits exist for.
 */
class AttachmentLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected Capture $capture;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->capture = Capture::factory()->create([
            'household_id' => Household::factory()->create()->id,
        ]);
    }

    protected function pdf(string $name, int $bytes): CaptureAttachment
    {
        $path = "captures/{$this->capture->id}/{$name}";
        Storage::disk('local')->put($path, str_repeat('P', $bytes));

        return CaptureAttachment::create([
            'capture_id' => $this->capture->id,
            'disk' => 'local',
            'path' => $path,
            'filename' => $name,
            'mime' => 'application/pdf',
            'size' => $bytes,
        ]);
    }

    #[Test]
    public function two_ordinary_pdfs_are_both_sent(): void
    {
        // The reported case: a real school email carrying two attachments.
        $this->pdf('term-dates.pdf', 3_000_000);
        $this->pdf('trip-letter.pdf', 2_000_000);

        $prepared = app(AttachmentPreparer::class)->prepareAll($this->capture->attachments);

        $this->assertCount(2, $prepared->blocks);
        $this->assertFalse($prepared->hasSkipped());
        $this->assertSame('document', $prepared->blocks[0]['type']);
    }

    #[Test]
    public function a_pdf_beyond_what_can_be_sent_is_named_not_dropped(): void
    {
        // Silently omitting it produces "nothing found", which is
        // indistinguishable from an email that had no dates in it.
        $this->pdf('huge-scan.pdf', AttachmentPreparer::MAX_BYTES + 1);

        $prepared = app(AttachmentPreparer::class)->prepareAll($this->capture->attachments);

        $this->assertSame([], $prepared->blocks);
        $this->assertSame(['huge-scan.pdf'], $prepared->skipped);
        $this->assertStringContainsString('huge-scan.pdf', $prepared->skippedSentence());
    }

    #[Test]
    public function attachments_share_one_request_budget(): void
    {
        // Each fits on its own; together they would overflow the request.
        $this->pdf('one.pdf', 11_000_000);
        $this->pdf('two.pdf', 11_000_000);

        $prepared = app(AttachmentPreparer::class)->prepareAll($this->capture->attachments);

        $this->assertCount(1, $prepared->blocks, 'The second must not push the request past the API ceiling.');
        $this->assertSame(['two.pdf'], $prepared->skipped);
    }

    #[Test]
    public function the_budget_is_measured_in_the_bytes_that_actually_travel(): void
    {
        // base64 inflates by a third, so 16MB of raw PDF is ~21MB on the wire
        // and does not fit a 20MB budget.
        $this->pdf('big.pdf', 16_000_000);

        $prepared = app(AttachmentPreparer::class)->prepareAll($this->capture->attachments);

        $this->assertSame(['big.pdf'], $prepared->skipped);
    }

    #[Test]
    public function the_webhook_accepts_what_postmark_can_send(): void
    {
        // Postmark's own ceiling is 35MB; ours must not be lower, or a real
        // email is rejected before anyone can be told why.
        $this->assertGreaterThanOrEqual(
            35_000_000,
            \App\Http\Controllers\Webhooks\PostmarkInboundController::MAX_ATTACHMENT_BYTES,
        );
    }
}
