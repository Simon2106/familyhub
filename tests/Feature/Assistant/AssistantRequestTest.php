<?php

namespace Tests\Feature\Assistant;

use Anthropic\Client;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Services\Assistant\AssistantPrompt;
use App\Services\Assistant\AssistantTools;
use App\Services\Assistant\ClaudeAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What actually goes on the wire, and what comes back off it.
 *
 * The loop is the part with teeth: a tool call that is not answered, or an
 * assistant turn that is not replayed, produces an infinite conversation.
 */
class AssistantRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    protected array $calls = [];

    /** @var list<object> */
    protected array $replies = [];

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
        $joey->aliases()->create(['alias' => 'Jo']);

        $school = Place::factory()->school()->create([
            'household_id' => $this->household->id, 'name' => 'Holy Trinity',
        ]);
        $school->members()->attach($joey->id, ['include_automatically' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** An assistant that records the request and replays queued responses. */
    protected function assistant(): ClaudeAssistant
    {
        return new class($this->calls, $this->replies) extends ClaudeAssistant
        {
            public function __construct(public array &$calls, public array &$replies)
            {
                parent::__construct(
                    // Constructed rather than resolved: the container's binding
                    // insists on a real key, and send() never uses this.
                    new Client(apiKey: 'test-key-unused'),
                    app(AssistantTools::class),
                    app(AssistantPrompt::class),
                );
            }

            protected function send(array $request): mixed
            {
                $this->calls[] = $request;

                return array_shift($this->replies)
                    ?? throw new RuntimeException('The test ran out of queued replies.');
            }
        };
    }

    /** @param list<array<string, mixed>> $content */
    protected function reply(string $stopReason, array $content): object
    {
        return new class($stopReason, $content)
        {
            public ?object $stopDetails = null;

            public ?object $usage = null;

            public array $content;

            public function __construct(public string $stopReason, array $content)
            {
                $this->content = array_map(fn (array $b) => (object) $b, $content);
            }
        };
    }

    protected function queueText(string $text): void
    {
        $this->replies[] = $this->reply('end_turn', [['type' => 'text', 'text' => $text]]);
    }

    protected function queueToolCall(string $name, array $input, string $id = 'toolu_1'): void
    {
        $this->replies[] = $this->reply('tool_use', [
            ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
        ]);
    }

    /* ----------------------------- the request ---------------------------- */

    #[Test]
    public function the_instructions_are_cached_and_the_day_is_not(): void
    {
        // Every question in the family's life shares the same instructions;
        // only the date and the household change, and only daily.
        $this->queueText('Nothing on.');

        $this->assistant()->ask('What is on today?', $this->household);

        $system = $this->calls[0]['system'];

        $this->assertCount(2, $system);
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cacheControl']);
        $this->assertArrayNotHasKey('cacheControl', $system[1]);
        $this->assertStringContainsString('Wednesday 9 September 2026', $system[1]['text']);
        $this->assertStringContainsString('2026-09-09', $system[1]['text']);
    }

    #[Test]
    public function the_household_is_described_the_way_the_capture_pipeline_describes_it(): void
    {
        $this->queueText('Nothing on.');

        $this->assistant()->ask('Where is Jo?', $this->household);

        $context = $this->calls[0]['system'][1]['text'];

        $this->assertStringContainsString('Joey (child)', $context);
        $this->assertStringContainsString('also written as Jo', $context);
        $this->assertStringContainsString('Holy Trinity', $context);
        $this->assertStringContainsString('Europe/London', $context);
    }

    #[Test]
    public function it_is_told_it_can_only_read(): void
    {
        $prompt = app(AssistantPrompt::class)->system();

        $this->assertStringContainsString('only read', $prompt);
        $this->assertStringContainsString('Say where the answer came from', $prompt);
        $this->assertStringContainsString('ask one short question instead of guessing', $prompt);
        // The kickboxing answer: a trip three months gone, offered as a plan.
        $this->assertStringContainsString('has already happened', $prompt);
        $this->assertStringContainsString('nothing upcoming', $prompt);
    }

    #[Test]
    public function every_tool_is_offered_and_none_of_them_writes(): void
    {
        $this->queueText('Nothing on.');

        $this->assistant()->ask('What is on today?', $this->household);

        $names = array_column($this->calls[0]['tools'], 'name');

        $this->assertEqualsCanonicalizing(
            ['calendar', 'meals', 'chores', 'lists', 'bins', 'school_dates', 'points', 'recipe', 'search'],
            $names,
        );

        foreach ($this->calls[0]['tools'] as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);

            // A tool whose name is a verb is a tool that changes something.
            foreach (['add', 'create', 'update', 'delete', 'set', 'tick', 'buy', 'send'] as $verb) {
                $this->assertStringNotContainsString($verb, $tool['name']);
            }
        }
    }

    #[Test]
    public function the_settings_come_from_config(): void
    {
        config()->set('familyhub.anthropic.assistant.model', 'claude-test-5');
        config()->set('familyhub.anthropic.assistant.effort', 'low');
        config()->set('familyhub.anthropic.assistant.max_tokens', 1234);

        $this->queueText('Nothing on.');

        $this->assistant()->ask('What is on today?', $this->household);

        $this->assertSame('claude-test-5', $this->calls[0]['model']);
        $this->assertSame(1234, $this->calls[0]['maxTokens']);
        $this->assertSame('low', $this->calls[0]['outputConfig']['effort']);
        // Sampling parameters are rejected outright by current models.
        $this->assertArrayNotHasKey('temperature', $this->calls[0]);
    }

    /* ------------------------------- the loop ----------------------------- */

    #[Test]
    public function a_tool_call_is_run_and_its_answer_sent_back(): void
    {
        $this->queueToolCall('calendar', ['from' => '2026-09-09', 'to' => '2026-09-09']);
        $this->queueText('Nothing on today.');

        $answer = $this->assistant()->ask('What is on today?', $this->household);

        $this->assertSame('Nothing on today.', $answer->text);
        $this->assertSame(['calendar'], $answer->used);

        // Second request: the assistant turn replayed whole, then the result.
        $messages = $this->calls[1]['messages'];

        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('user', $messages[2]['role']);
        $this->assertSame('tool_result', $messages[2]['content'][0]['type']);
        $this->assertSame('toolu_1', $messages[2]['content'][0]['toolUseID']);
        $this->assertStringContainsString('Nothing in the calendar', $messages[2]['content'][0]['content']);
    }

    #[Test]
    public function two_tools_asked_for_at_once_are_both_answered(): void
    {
        // Splitting parallel calls across turns teaches the model to stop
        // making them.
        $this->replies[] = $this->reply('tool_use', [
            ['type' => 'tool_use', 'id' => 'a', 'name' => 'meals', 'input' => ['from' => '2026-09-09', 'to' => '2026-09-09']],
            ['type' => 'tool_use', 'id' => 'b', 'name' => 'bins', 'input' => []],
        ]);
        $this->queueText('Fish pie, and the recycling.');

        $answer = $this->assistant()->ask('Tea and bins?', $this->household);

        $results = $this->calls[1]['messages'][2]['content'];

        $this->assertCount(2, $results);
        $this->assertSame(['a', 'b'], array_column($results, 'toolUseID'));
        $this->assertSame(['meals', 'bins'], $answer->used);
    }

    #[Test]
    public function an_unknown_tool_is_answered_rather_than_thrown(): void
    {
        $this->queueToolCall('delete_everything', []);
        $this->queueText('I cannot do that.');

        $answer = $this->assistant()->ask('Delete the calendar', $this->household);

        $this->assertSame('I cannot do that.', $answer->text);
        $this->assertStringContainsString(
            'no tool called',
            $this->calls[1]['messages'][2]['content'][0]['content'],
        );
    }

    #[Test]
    public function earlier_turns_are_replayed_so_a_follow_up_means_something(): void
    {
        $this->queueText('Fish pie.');

        $this->assistant()->ask('And the week after?', $this->household, [
            ['role' => 'user', 'content' => 'What is for tea this week?'],
            ['role' => 'assistant', 'content' => 'Fish pie on Thursday.'],
        ]);

        $messages = $this->calls[0]['messages'];

        $this->assertCount(3, $messages);
        $this->assertSame('What is for tea this week?', $messages[0]['content']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('And the week after?', $messages[2]['content']);
    }

    #[Test]
    public function a_conversation_that_never_settles_is_stopped(): void
    {
        config()->set('familyhub.anthropic.assistant.max_steps', 2);

        $this->queueToolCall('search', ['query' => 'party'], 'one');
        $this->queueToolCall('search', ['query' => 'party'], 'two');

        $this->expectExceptionMessage('more looking up than expected');

        $this->assistant()->ask('When is the party?', $this->household);
    }

    #[Test]
    public function a_refusal_is_said_out_loud(): void
    {
        $this->replies[] = $this->reply('refusal', []);

        $this->expectExceptionMessage('declined');

        $this->assistant()->ask('Something objectionable', $this->household);
    }

    #[Test]
    public function an_answer_cut_short_is_not_passed_off_as_an_answer(): void
    {
        $this->replies[] = $this->reply('max_tokens', [['type' => 'text', 'text' => 'It is on Tues']]);

        $this->expectExceptionMessage('past its length limit');

        $this->assistant()->ask('List everything', $this->household);
    }
}
