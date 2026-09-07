<?php

namespace Tests\Feature\Capture;

use App\Http\Controllers\Webhooks\PostmarkInboundController;
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

    /** An attachment whose stored file is genuinely past the ceiling. */
    protected function oversizedPdf(string $name): CaptureAttachment
    {
        return $this->pdf($name, AttachmentPreparer::MAX_BYTES + 1);
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

        $prepared = app(AttachmentPreparer::class)->prepareEach($this->capture->attachments);

        $this->assertCount(2, $prepared->blocks);
        $this->assertFalse($prepared->hasSkipped());
        $this->assertSame('document', $prepared->blocks[0]['type']);
    }

    #[Test]
    public function a_pdf_beyond_what_can_be_sent_is_named_not_dropped(): void
    {
        // Silently omitting it produces "nothing found", which is
        // indistinguishable from an email that had no dates in it.
        // Just over the ceiling, without materialising the whole thing: the
        // size column is what the preparer reads before touching the file.
        $this->oversizedPdf('huge-scan.pdf');

        $prepared = app(AttachmentPreparer::class)->prepareEach($this->capture->attachments);

        $this->assertSame([], $prepared->blocks);
        $this->assertSame(['huge-scan.pdf'], $prepared->skipped);
        $this->assertStringContainsString('huge-scan.pdf', $prepared->skippedSentence());
    }

    #[Test]
    public function a_large_pdf_no_longer_starves_the_others(): void
    {
        // Each attachment gets a request of its own, so nothing is summed
        // against a shared cap. Asserted by reflection rather than by
        // allocating tens of megabytes of base64 in the test process.
        $this->assertFalse(
            (new \ReflectionClass(AttachmentPreparer::class))->hasConstant('MAX_TOTAL_BYTES'),
            'A shared budget would let one large attachment crowd out the rest.',
        );

        $this->pdf('one.pdf', 900_000);
        $this->pdf('two.pdf', 900_000);
        $this->pdf('three.pdf', 900_000);

        $prepared = app(AttachmentPreparer::class)->prepareEach($this->capture->attachments);

        $this->assertCount(3, $prepared->blocks);
        $this->assertFalse($prepared->hasSkipped());
    }

    #[Test]
    public function the_per_attachment_ceiling_leaves_room_for_base64(): void
    {
        // base64 inflates by a third, so the raw ceiling has to sit below the
        // API's 32MB request limit with room for the prompt.
        $this->assertLessThan(
            32_000_000 * 3 / 4,
            AttachmentPreparer::MAX_BYTES,
            'A single attachment at the ceiling must still fit one request once base64-encoded.',
        );
    }

    #[Test]
    public function the_webhook_accepts_what_postmark_can_send(): void
    {
        // Postmark's own ceiling is 35MB; ours must not be lower, or a real
        // email is rejected before anyone can be told why.
        $this->assertGreaterThanOrEqual(
            35_000_000,
            PostmarkInboundController::MAX_ATTACHMENT_BYTES,
        );
    }
}
