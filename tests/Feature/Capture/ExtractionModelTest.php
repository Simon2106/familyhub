<?php

namespace Tests\Feature\Capture;

use Anthropic\Client;
use App\Services\Capture\AttachmentPreparer;
use App\Services\Capture\ClaudeItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The model choice and how an unusable response is reported.
 */
class ExtractionModelTest extends TestCase
{
    use RefreshDatabase;

    protected function extractor(): ClaudeItemExtractor
    {
        // No client call is made: only interpret() is exercised.
        return new ClaudeItemExtractor(
            $this->createMock(Client::class),
            new AttachmentPreparer,
        );
    }

    /** A stand-in for the SDK's Message, carrying only what interpret() reads. */
    protected function message(string $stopReason, string $text = '', ?string $refusal = null): object
    {
        return new class($stopReason, $text, $refusal)
        {
            public array $content;

            public function __construct(
                public string $stopReason,
                string $text,
                ?string $refusal,
            ) {
                $this->content = $text === '' ? [] : [(object) ['type' => 'text', 'text' => $text]];
                $this->stopDetails = $refusal === null ? null : (object) ['explanation' => $refusal];
            }

            public ?object $stopDetails;
        };
    }

    #[Test]
    public function the_default_model_is_sonnet_5(): void
    {
        $this->assertSame('claude-sonnet-5', config('familyhub.anthropic.model'));
    }

    #[Test]
    public function the_model_is_configurable(): void
    {
        config(['familyhub.anthropic.model' => 'claude-opus-5']);

        $this->assertSame('claude-opus-5', config('familyhub.anthropic.model'));
    }

    #[Test]
    public function a_normal_response_is_parsed(): void
    {
        $json = json_encode([
            'summary' => 'A newsletter.',
            'items' => [['type' => 'event', 'title' => 'Sports day', 'start' => '2026-06-12', 'confidence' => 90]],
        ]);

        $result = $this->extractor()->interpret($this->message('end_turn', $json));

        $this->assertSame('A newsletter.', $result->summary);
        $this->assertSame('Sports day', $result->items[0]->title);
    }

    #[Test]
    public function a_truncated_answer_says_so_rather_than_looking_like_bad_json(): void
    {
        // Sonnet 5 thinks adaptively by default and that spend shares the
        // max_tokens budget, so a long term calendar really can be cut off.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_MAX_TOKENS');

        $this->extractor()->interpret($this->message('max_tokens', '{"items":[{"title":"Half a'));
    }

    #[Test]
    public function a_refusal_is_reported_with_its_reason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined to read this capture: nope');

        $this->extractor()->interpret($this->message('refusal', '', 'nope'));
    }

    #[Test]
    public function a_response_with_no_text_block_is_an_error_not_a_silent_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no text block');

        $this->extractor()->interpret($this->message('end_turn'));
    }

    #[Test]
    public function a_non_json_answer_is_reported_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not JSON');

        $this->extractor()->interpret($this->message('end_turn', 'Sorry, I had a look and...'));
    }
}
