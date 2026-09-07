<?php

namespace App\Services\Recipes;

use Anthropic\Client;
use App\Services\Recipes\Contracts\RecipeReader;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a saved meal idea to Claude and reads back a recipe card.
 *
 * Structured outputs, like the capture extractor: one shape is wanted back and
 * there are no tools to call, so constraining the response beats hunting for
 * JSON in prose.
 */
class ClaudeRecipeReader implements RecipeReader
{
    public function __construct(protected Client $client) {}

    /** @param list<array<string, mixed>> $content */
    public function read(array $content): RecipeResult
    {
        $message = $this->send([
            'model' => config('familyhub.anthropic.model'),
            'maxTokens' => config('familyhub.anthropic.max_tokens'),
            // The instructions never vary, so every import after the first
            // reads them from cache rather than paying for them again.
            'system' => [[
                'type' => 'text',
                'text' => RecipeSchema::systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $content]],
            'outputConfig' => [
                // Transcription rather than reasoning, and thinking shares the
                // same budget as the answer — a long ingredients list needs it.
                'effort' => config('familyhub.anthropic.effort'),
                'format' => ['type' => 'json_schema', 'schema' => RecipeSchema::schema()],
            ],
        ]);

        Log::info('Recipe read', [
            'blocks' => array_map(fn (array $b) => $b['type'], $content),
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
            'cache_read_tokens' => $message->usage->cacheReadInputTokens ?? null,
        ]);

        return $this->interpret($message);
    }

    public function interpret(mixed $message): RecipeResult
    {
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException(
                'Claude declined to read this recipe'
                .($message->stopDetails?->explanation ? ': '.$message->stopDetails->explanation : '.')
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The recipe ran past the token budget. Raise ANTHROPIC_MAX_TOKENS '
                .'(currently '.config('familyhub.anthropic.max_tokens').') and try again.'
            );
        }

        return RecipeParser::fromJson($this->firstText($message));
    }

    /** The single place the SDK is called, isolated so tests can stand in front of it. */
    protected function send(array $request): mixed
    {
        return $this->client->messages->create(...$request);
    }

    protected function firstText(mixed $message): string
    {
        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text' && filled($block->text ?? null)) {
                return $block->text;
            }
        }

        throw new RuntimeException('The model returned nothing to read.');
    }
}
