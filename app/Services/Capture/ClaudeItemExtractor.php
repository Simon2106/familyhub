<?php

namespace App\Services\Capture;

use Anthropic\Client;
use App\Models\Capture;
use App\Models\CaptureAttachment;
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
        $content = $this->buildContent($capture);

        if ($content === []) {
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

        return $this->interpret($message);
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
    protected function buildContent(Capture $capture): array
    {
        $content = [];

        // Documents first: the API reads them better when they precede the
        // instruction that refers to them.
        foreach ($capture->attachments as $attachment) {
            if ($block = $this->attachmentBlock($attachment)) {
                $content[] = $block;
            }
        }

        $text = $this->describe($capture);

        if (trim($text) === '' && $content === []) {
            return [];
        }

        $content[] = ['type' => 'text', 'text' => $text];

        return $content;
    }

    /** @return array<string, mixed>|null */
    protected function attachmentBlock(CaptureAttachment $attachment): ?array
    {
        $prepared = $this->attachments->prepare($attachment);

        if ($prepared === null) {
            return null;
        }

        [$data, $mime] = $prepared;

        if ($mime === 'application/pdf') {
            return [
                'type' => 'document',
                'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $data],
            ];
        }

        return [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $data],
        ];
    }

    /** The volatile half of the prompt — kept out of the cached system block. */
    protected function describe(Capture $capture): string
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

        if ($capture->attachments->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Attachments are included above. Read them fully.';
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
