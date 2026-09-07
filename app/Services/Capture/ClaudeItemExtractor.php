<?php

namespace App\Services\Capture;

use Anthropic\Client;
use App\Models\Capture;
use App\Services\Capture\Contracts\ItemExtractor;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Sends a capture to Claude and reads back the structured result.
 *
 * The response shape is constrained by ExtractionSchema, so this never has to
 * hunt for JSON in prose.
 */
class ClaudeItemExtractor implements ItemExtractor
{
    public function __construct(
        protected Client $client,
        protected AttachmentPreparer $attachments,
    ) {}

    public function extract(Capture $capture): ExtractionResult
    {
        $prepared = $this->attachments->prepareAll($capture->attachments);

        $content = $this->buildContent($capture, $prepared);

        if ($content === []) {
            // Everything that arrived was unreadable. Failing loudly is right:
            // reporting "nothing found" would look identical to an email that
            // genuinely had no dates in it.
            if ($prepared->hasSkipped()) {
                throw new RuntimeException($prepared->skippedSentence());
            }

            return new ExtractionResult;
        }

        $message = $this->client->messages->create(
            model: config('familyhub.anthropic.model'),
            maxTokens: config('familyhub.anthropic.max_tokens'),
            // Cached: the instructions never vary, so every capture after the
            // first reads them from cache rather than paying for them again.
            system: [[
                'type' => 'text',
                'text' => ExtractionSchema::systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => ExtractionSchema::schema()],
            ],
        );

        // No temperature or top_p: Sonnet 5 and the other current models reject
        // sampling parameters outright.

        $result = $this->interpret($message);

        // A partial read is still a useful read, but the review inbox has to
        // say what was left out.
        return $prepared->hasSkipped()
            ? new ExtractionResult(
                $result->items,
                trim(($result->summary ?? '').' '.$prepared->skippedSentence()),
            )
            : $result;
    }

    /**
     * Reads a response, refusing the two ways it can be unusable.
     *
     * Kept separate from the request so both paths can be tested without an
     * API call; typed loosely for the same reason.
     */
    public function interpret(mixed $message): ExtractionResult
    {
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException(
                'Claude declined to read this capture'
                .($message->stopDetails?->explanation ? ': '.$message->stopDetails->explanation : '.')
            );
        }

        // Sonnet 5 thinks adaptively by default and that spend shares the
        // max_tokens budget, so a long term calendar can be cut off mid-JSON.
        // Saying so beats letting it surface as "not valid JSON".
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The answer ran past the token budget. Raise ANTHROPIC_MAX_TOKENS '
                .'(currently '.config('familyhub.anthropic.max_tokens').') and try again.'
            );
        }

        return $this->parse($this->firstText($message));
    }

    /**
     * The user turn: what was sent, plus any readable attachments.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildContent(Capture $capture, PreparedAttachments $prepared): array
    {
        // Documents first: the API reads them better when they precede the
        // instruction that refers to them.
        $content = $prepared->blocks;

        $text = $this->describe($capture, $prepared);

        if (trim($text) === '' && $content === []) {
            return [];
        }

        $content[] = ['type' => 'text', 'text' => $text];

        return $content;
    }

    /** The volatile half of the prompt — kept out of the cached system block. */
    protected function describe(Capture $capture, PreparedAttachments $prepared): string
    {
        $household = $capture->household;
        $sentAt = $capture->created_at ?? now();

        $lines = [
            'Today is '.$household->nowLocal()->format('l j F Y').'.',
            'This was received on '.$sentAt->timezone($household->displayTimezone())->format('l j F Y').
                ' — use that date to resolve anything relative, and to infer a missing year.',
            'The household timezone is '.$household->displayTimezone().'. Give all times in local time.',
        ];

        $members = $household->members->pluck('name')->all();

        if ($members !== []) {
            $lines[] = 'The family members are: '.implode(', ', $members).
                '. Only use these to fill member_hint if the source actually names one.';
        }

        $lines[] = '';
        $lines[] = 'Source: '.$capture->source;

        if ($capture->sender) {
            $lines[] = 'From: '.$capture->sender;
        }

        if ($capture->subject) {
            $lines[] = 'Subject: '.$capture->subject;
        }

        if (filled($capture->body_text)) {
            $lines[] = '';
            $lines[] = '--- content ---';
            $lines[] = $capture->body_text;
        }

        if ($prepared->blocks !== []) {
            $lines[] = '';
            $lines[] = 'Attachments are included above. Read them fully.';
        }

        if ($prepared->hasSkipped()) {
            // Told to the model too, so it does not claim to have read
            // something that was never sent.
            $lines[] = '';
            $lines[] = 'Not included (too large to send): '.implode(', ', $prepared->skipped).'.';
        }

        return implode("\n", $lines);
    }

    protected function firstText(mixed $message): string
    {
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new RuntimeException('Claude returned no text block.');
    }

    protected function parse(string $json): ExtractionResult
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Claude returned something that was not JSON.');
        }

        return ExtractionParser::fromArray($decoded);
    }
}
