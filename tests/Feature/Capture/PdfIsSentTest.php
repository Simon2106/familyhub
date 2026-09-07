<?php

namespace Tests\Feature\Capture;

use Anthropic\Client;
use App\Models\Capture;
use App\Models\CaptureAttachment;
use App\Models\Household;
use App\Services\Capture\AttachmentPreparer;
use App\Services\Capture\ClaudeItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A real "FW: Flu vaccination" email came back saying the date was "only in
 * attached instructions" while the PDF's first page gave it. These prove the
 * PDF reaches the model intact — if it does, a miss is the prompt's fault, not
 * the plumbing's.
 */
class PdfIsSentTest extends TestCase
{
    use RefreshDatabase;

    protected array $calls = [];

    protected Capture $capture;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->capture = Capture::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'subject' => 'FW: Flu vaccination',
            'body_text' => 'See attached for the details.',
        ]);
    }

    protected function fixtureBytes(): string
    {
        return file_get_contents(base_path('tests/Fixtures/flu-letter.pdf'));
    }

    protected function attachFixture(string $name = 'flu-letter.pdf'): CaptureAttachment
    {
        $bytes = $this->fixtureBytes();
        $path = "captures/{$this->capture->id}/{$name}";
        Storage::disk('local')->put($path, $bytes);

        return CaptureAttachment::create([
            'capture_id' => $this->capture->id,
            'disk' => 'local', 'path' => $path, 'filename' => $name,
            'mime' => 'application/pdf', 'size' => strlen($bytes),
        ]);
    }

    protected function extractor(): ClaudeItemExtractor
    {
        return new class($this->calls) extends ClaudeItemExtractor
        {
            public function __construct(public array &$calls)
            {
                parent::__construct(new Client(apiKey: 'unused'), new AttachmentPreparer);
            }

            protected function send(array $request): mixed
            {
                $this->calls[] = $request;

                return new class
                {
                    public string $stopReason = 'end_turn';

                    public ?object $stopDetails = null;

                    public object $usage;

                    public array $content;

                    public function __construct()
                    {
                        $this->usage = (object) [
                            'inputTokens' => 4321, 'outputTokens' => 210,
                            'cacheReadInputTokens' => 1000,
                        ];
                        $this->content = [(object) ['type' => 'text', 'text' => '{"items":[],"summary":"Read."}']];
                    }
                };
            }
        };
    }

    /** @return list<array<string, mixed>> */
    protected function documentBlocks(): array
    {
        $documents = [];

        foreach ($this->calls as $call) {
            foreach ($call['messages'][0]['content'] as $block) {
                if (($block['type'] ?? null) === 'document') {
                    $documents[] = $block;
                }
            }
        }

        return $documents;
    }

    #[Test]
    public function the_fixture_really_is_a_pdf_carrying_the_date(): void
    {
        $bytes = $this->fixtureBytes();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('Holy Trinity School - 25th September 2026', $bytes);
    }

    #[Test]
    public function the_pdf_is_sent_as_a_document_block(): void
    {
        $this->attachFixture();

        $this->extractor()->extract($this->capture->fresh());

        $documents = $this->documentBlocks();

        $this->assertCount(1, $documents, 'The PDF never reached the model.');
        $this->assertSame('base64', $documents[0]['source']['type']);
        $this->assertSame('application/pdf', $documents[0]['source']['mediaType']);
    }

    #[Test]
    public function the_bytes_that_arrive_are_the_bytes_on_disk(): void
    {
        $this->attachFixture();

        $this->extractor()->extract($this->capture->fresh());

        $decoded = base64_decode($this->documentBlocks()[0]['source']['data'], strict: true);

        $this->assertSame($this->fixtureBytes(), $decoded, 'The PDF was altered on the way.');
        // The date the real email missed is demonstrably in what we send.
        $this->assertStringContainsString('Holy Trinity School - 25th September 2026', $decoded);
    }

    #[Test]
    public function the_body_is_read_as_well_as_the_pdf(): void
    {
        $this->attachFixture();

        $this->extractor()->extract($this->capture->fresh());

        // One call for the attachment, one for the cover note.
        $this->assertCount(2, $this->calls);
    }

    #[Test]
    public function two_pdfs_are_both_sent(): void
    {
        $this->attachFixture('term-dates.pdf');
        $this->attachFixture('flu-letter.pdf');

        $this->extractor()->extract($this->capture->fresh());

        $this->assertCount(2, $this->documentBlocks());
    }

    #[Test]
    public function what_was_sent_is_logged_with_its_token_cost(): void
    {
        // Without this, an attachment that never reached the model looks
        // exactly like one the model read and found nothing in.
        Log::spy();

        $this->attachFixture();
        $this->extractor()->extract($this->capture->fresh());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Capture part read'
                && $context['part'] === 'flu-letter.pdf'
                && in_array('document', $context['blocks'], true)
                && $context['input_tokens'] === 4321)
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Capture part read'
                && $context['part'] === 'message body')
            ->once();
    }
}
