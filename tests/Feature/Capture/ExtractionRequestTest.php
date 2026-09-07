<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\CaptureAttachment;
use App\Models\Household;
use App\Services\Capture\AttachmentPreparer;
use App\Services\Capture\ClaudeItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What actually goes on the wire: how many calls, and with what settings.
 */
class ExtractionRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    protected array $calls = [];

    protected Capture $capture;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->capture = Capture::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'body_text' => 'Parents evening is on 15 September.',
        ]);
    }

    /** An extractor that records the request instead of sending it. */
    protected function extractor(): ClaudeItemExtractor
    {
        return new class($this->calls) extends ClaudeItemExtractor
        {
            public function __construct(public array &$calls)
            {
                parent::__construct(
                    // Constructed directly, not resolved: the container's
                    // binding demands a real key, and send() is overridden so
                    // this client is never used.
                    new \Anthropic\Client(apiKey: 'test-key-unused'),
                    new AttachmentPreparer,
                );
            }

            protected function send(array $request): mixed
            {
                $this->calls[] = $request;

                return new class
                {
                    public string $stopReason = 'end_turn';

                    public ?object $stopDetails = null;

                    public array $content;

                    public function __construct()
                    {
                        $this->content = [(object) [
                            'type' => 'text',
                            'text' => '{"items":[],"summary":"Read."}',
                        ]];
                    }
                };
            }
        };
    }

    protected function pdf(string $name): void
    {
        $path = "captures/{$this->capture->id}/{$name}";
        Storage::disk('local')->put($path, str_repeat('P', 1000));

        CaptureAttachment::create([
            'capture_id' => $this->capture->id,
            'disk' => 'local', 'path' => $path, 'filename' => $name,
            'mime' => 'application/pdf', 'size' => 1000,
        ]);
    }

    #[Test]
    public function a_body_only_capture_is_one_call(): void
    {
        $this->extractor()->extract($this->capture);

        $this->assertCount(1, $this->calls);
    }

    #[Test]
    public function each_attachment_gets_its_own_call_plus_one_for_the_body(): void
    {
        // The reported case: a newsletter with two PDFs. One call for
        // everything meant the first PDF spent most of the budget.
        $this->pdf('term-dates.pdf');
        $this->pdf('trip-letter.pdf');

        $this->extractor()->extract($this->capture->fresh());

        $this->assertCount(3, $this->calls);
    }

    #[Test]
    public function a_capture_with_no_body_is_one_call_per_attachment(): void
    {
        $this->capture->update(['body_text' => null]);
        $this->pdf('term-dates.pdf');

        $this->extractor()->extract($this->capture->fresh());

        $this->assertCount(1, $this->calls);
    }

    #[Test]
    public function each_attachment_call_carries_exactly_one_document(): void
    {
        $this->pdf('a.pdf');
        $this->pdf('b.pdf');

        $this->extractor()->extract($this->capture->fresh());

        foreach ($this->calls as $call) {
            $content = $call['messages'][0]['content'];
            $documents = array_filter($content, fn ($b) => ($b['type'] ?? null) === 'document');

            $this->assertLessThanOrEqual(1, count($documents), 'A call must not carry two attachments.');
        }
    }

    #[Test]
    public function thinking_is_capped_so_the_budget_goes_to_the_json(): void
    {
        // Extraction is transcription, not reasoning, and adaptive thinking
        // shares max_tokens with the answer.
        $this->extractor()->extract($this->capture);

        $this->assertSame('low', $this->calls[0]['outputConfig']['effort']);
    }

    #[Test]
    public function the_effort_is_configurable(): void
    {
        config(['familyhub.anthropic.effort' => 'medium']);

        $this->extractor()->extract($this->capture);

        $this->assertSame('medium', $this->calls[0]['outputConfig']['effort']);
    }

    #[Test]
    public function the_token_ceiling_covers_thinking_and_a_long_term_calendar(): void
    {
        $this->extractor()->extract($this->capture);

        $this->assertSame(32000, $this->calls[0]['maxTokens']);
        $this->assertSame(32000, config('familyhub.anthropic.max_tokens'));
    }

    #[Test]
    public function no_sampling_parameters_are_sent(): void
    {
        // Current models reject temperature and top_p outright.
        $this->extractor()->extract($this->capture);

        $this->assertArrayNotHasKey('temperature', $this->calls[0]);
        $this->assertArrayNotHasKey('topP', $this->calls[0]);
    }

    #[Test]
    public function the_system_prompt_is_cached_so_later_calls_do_not_repay_for_it(): void
    {
        $this->pdf('a.pdf');
        $this->pdf('b.pdf');

        $this->extractor()->extract($this->capture->fresh());

        foreach ($this->calls as $call) {
            $this->assertSame(['type' => 'ephemeral'], $call['system'][0]['cacheControl']);
        }
    }

    #[Test]
    public function an_attachment_call_is_told_to_read_only_that_attachment(): void
    {
        $this->pdf('a.pdf');
        $this->pdf('b.pdf');

        $this->extractor()->extract($this->capture->fresh());

        $texts = array_map(
            fn ($call) => collect($call['messages'][0]['content'])->firstWhere('type', 'text')['text'] ?? '',
            $this->calls,
        );

        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'attachment 1 of 2')));
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'attachment 2 of 2')));
        // And the body call must not re-read what the others are covering.
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'read separately')));
    }
}
