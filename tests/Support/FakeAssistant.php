<?php

namespace Tests\Support;

use App\Models\Household;
use App\Services\Assistant\AssistantAnswer;
use App\Services\Assistant\Contracts\Assistant;
use RuntimeException;

/**
 * Stands in for Claude on the page.
 *
 * The tools and the prompt are tested against the real classes elsewhere; this
 * only removes the network from the component tests.
 */
class FakeAssistant implements Assistant
{
    /** @var list<AssistantAnswer> */
    public array $answers = [];

    /** @var list<array{question: string, history: array}> */
    public array $asked = [];

    public ?string $throw = null;

    /** @param list<string> $used */
    public function queue(string $text, array $used = []): self
    {
        $this->answers[] = new AssistantAnswer($text, $used);

        return $this;
    }

    public function ask(string $question, Household $household, array $history = []): AssistantAnswer
    {
        $this->asked[] = ['question' => $question, 'history' => $history];

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        return array_shift($this->answers) ?? new AssistantAnswer('I do not know.', []);
    }
}
