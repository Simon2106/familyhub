<?php

namespace App\Services\Assistant;

use Anthropic\Client;
use App\Models\Household;
use App\Services\Assistant\Contracts\Assistant;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Asks Claude a question about the household, with tools instead of a database.
 *
 * The model is given nine small read-only tools and left to decide which to
 * call. That is the whole reason this is not a prompt with the week's data
 * pasted into it: a fortnight of events, every list and every chore is both
 * far more than any one question needs and never quite enough for the next.
 */
class ClaudeAssistant implements Assistant
{
    public function __construct(
        protected Client $client,
        protected AssistantTools $tools,
        protected AssistantPrompt $prompt,
    ) {}

    public function ask(string $question, Household $household, array $history = []): AssistantAnswer
    {
        $messages = [...$this->transcript($history), ['role' => 'user', 'content' => $question]];
        $used = [];

        // A bound, not a target: a question that needs six lookups is a
        // question that has gone wrong, and a loop with no ceiling on a
        // family's phone is a bill with no ceiling either.
        $steps = max(1, (int) config('familyhub.anthropic.assistant.max_steps'));

        for ($step = 0; $step < $steps; $step++) {
            $message = $this->send($this->request($messages, $household));

            $this->refuseUnusable($message);

            $calls = $this->toolCalls($message);

            if ($calls === []) {
                return new AssistantAnswer($this->text($message), $used);
            }

            $results = [];

            foreach ($calls as $call) {
                $used[] = $call['name'];

                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $call['id'],
                    'content' => $this->tools->run($call['name'], $call['input'], $household),
                ];
            }

            Log::info('Assistant looked something up', [
                'step' => $step + 1,
                'tools' => array_column($calls, 'name'),
                'input_tokens' => $message->usage->inputTokens ?? null,
                'output_tokens' => $message->usage->outputTokens ?? null,
            ]);

            // The assistant turn goes back whole — thinking blocks and all,
            // which the model requires unchanged when it continues its own
            // reasoning across a tool call.
            $messages[] = ['role' => 'assistant', 'content' => $message->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new RuntimeException('That took more looking up than expected. Try asking it in a narrower way.');
    }

    /**
     * The one call to the SDK, kept alone in a method so a test can assert the
     * request without standing up an HTTP client.
     *
     * @param  array<string, mixed>  $request
     */
    protected function send(array $request): mixed
    {
        return $this->client->messages->create(...$request);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function request(array $messages, Household $household): array
    {
        return [
            'model' => config('familyhub.anthropic.assistant.model'),
            'maxTokens' => (int) config('familyhub.anthropic.assistant.max_tokens'),
            'system' => [
                [
                    // Cached: the instructions and the tool descriptions are
                    // the same on every question anyone ever asks.
                    'type' => 'text',
                    'text' => $this->prompt->system(),
                    'cacheControl' => ['type' => 'ephemeral'],
                ],
                // After the breakpoint, because it changes daily.
                ['type' => 'text', 'text' => $this->prompt->context($household)],
            ],
            'messages' => $messages,
            'tools' => $this->tools->definitions(),
            'outputConfig' => ['effort' => config('familyhub.anthropic.assistant.effort')],
        ];
    }

    /**
     * Earlier turns, so "and the week after?" has something to refer back to.
     *
     * Only the words are replayed, not the tool calls that produced them: the
     * model is told to look everything up again anyway, and a transcript of
     * plain text is one that can live in a Livewire component without being
     * serialised into something it is not.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array<string, mixed>>
     */
    protected function transcript(array $history): array
    {
        return array_values(array_map(
            fn (array $turn) => [
                'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $turn['content'],
            ],
            array_filter($history, fn (array $turn) => filled($turn['content'] ?? null)),
        ));
    }

    /**
     * The two ways a response is not an answer.
     *
     * Both are quiet failures otherwise: a refusal has no text block to read,
     * and a truncated answer reads as a confident half-sentence.
     */
    protected function refuseUnusable(mixed $message): void
    {
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException(
                'Claude declined to answer that'
                .($message->stopDetails?->explanation ? ': '.$message->stopDetails->explanation : '.')
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('The answer ran past its length limit. Try asking for less at once.');
        }
    }

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    protected function toolCalls(mixed $message): array
    {
        $calls = [];

        foreach ($message->content as $block) {
            if ($block->type === 'tool_use') {
                $calls[] = [
                    'id' => $block->id,
                    'name' => $block->name,
                    'input' => (array) ($block->input ?? []),
                ];
            }
        }

        return $calls;
    }

    protected function text(mixed $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= ($text === '' ? '' : "\n").$block->text;
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('Claude answered with nothing at all.');
        }

        return trim($text);
    }
}
