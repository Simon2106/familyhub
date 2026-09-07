<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Services\Capture\AttachmentPreparer;
use App\Services\Capture\ClaudeItemExtractor;
use App\Services\Capture\ExtractionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the model is actually told about this household and this message.
 */
class PromptContextTest extends TestCase
{
    use RefreshDatabase;

    protected array $calls = [];

    protected Household $household;

    protected Capture $capture;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true]);
        $joey->aliases()->create(['alias' => 'Jo']);

        $simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $simon->aliases()->create(['alias' => 'SW']);

        $school = Place::factory()->school()->create(['household_id' => $this->household->id, 'name' => 'Holy Trinity']);
        $school->aliases()->create(['alias' => 'HT']);
        $school->members()->attach($joey->id, ['include_automatically' => true]);

        $this->capture = Capture::factory()->create([
            'household_id' => $this->household->id,
            'subject' => 'FW: Flu vaccination',
            'sender' => 'Office <office@holytrinity.sch.uk>',
            'body_text' => 'See attached.',
        ]);
    }

    protected function extractor(): ClaudeItemExtractor
    {
        return new class($this->calls) extends ClaudeItemExtractor
        {
            public function __construct(public array &$calls)
            {
                parent::__construct(new \Anthropic\Client(apiKey: 'unused'), new AttachmentPreparer);
            }

            protected function send(array $request): mixed
            {
                $this->calls[] = $request;

                return new class
                {
                    public string $stopReason = 'end_turn';

                    public ?object $stopDetails = null;

                    public ?object $usage = null;

                    public array $content;

                    public function __construct()
                    {
                        $this->content = [(object) ['type' => 'text', 'text' => '{"items":[],"summary":"Read."}']];
                    }
                };
            }
        };
    }

    protected function userText(): string
    {
        $this->extractor()->extract($this->capture->fresh()->load([
            'household.members.aliases', 'household.places.aliases', 'household.places.members',
        ]));

        return collect($this->calls[0]['messages'][0]['content'])->firstWhere('type', 'text')['text'];
    }

    #[Test]
    public function the_members_and_their_aliases_are_given(): void
    {
        $text = $this->userText();

        $this->assertStringContainsString('Joey', $text);
        $this->assertStringContainsString('also written as Jo', $text);
        $this->assertStringContainsString('Simon', $text);
        $this->assertStringContainsString('SW', $text);
    }

    #[Test]
    public function children_and_adults_are_distinguished(): void
    {
        // The form is the parent's task even when the child's school wrote it,
        // which only works if the model knows which is which.
        $text = $this->userText();

        $this->assertStringContainsString('Joey (child)', $text);
        $this->assertStringContainsString('Simon (adult)', $text);
    }

    #[Test]
    public function places_are_given_with_their_aliases_and_who_they_concern(): void
    {
        $text = $this->userText();

        $this->assertStringContainsString('Holy Trinity / HT', $text);
        $this->assertStringContainsString('school', $text);
        $this->assertStringContainsString('Joey', $text);
    }

    #[Test]
    public function the_sender_domain_is_offered_as_a_hint(): void
    {
        // Often the only thing naming the school when the letter does not.
        $this->assertStringContainsString('holytrinity.sch.uk', $this->userText());
    }

    #[Test]
    public function a_sender_without_an_address_does_not_break_the_prompt(): void
    {
        $this->capture->update(['sender' => 'The School Office']);

        $this->assertStringNotContainsString('Sender domain:', $this->userText());
    }

    #[Test]
    public function a_household_with_no_places_still_gets_its_members(): void
    {
        Place::query()->delete();

        $text = $this->userText();

        $this->assertStringContainsString('The household:', $text);
        $this->assertStringNotContainsString('Places in their lives:', $text);
    }

    #[Test]
    public function the_prompt_says_attachments_are_the_source(): void
    {
        // The reported failure: the model called the date unavailable while it
        // was printed in the attachment.
        $prompt = ExtractionSchema::systemPrompt();

        $this->assertStringContainsString('THAT is the source', $prompt);
        $this->assertStringContainsString('covering note', $prompt);
    }

    #[Test]
    public function the_prompt_asks_for_both_the_event_and_its_deadline(): void
    {
        $prompt = ExtractionSchema::systemPrompt();

        $this->assertStringContainsString('Produce', $prompt);
        $this->assertStringContainsString('consent form', $prompt);
        // Counting back over a weekend is the part most easily got wrong.
        $this->assertStringContainsString('weekday', $prompt);
    }

    #[Test]
    public function the_prompt_prefers_a_dated_task(): void
    {
        $this->assertStringContainsString('should be rare', ExtractionSchema::systemPrompt());
    }

    #[Test]
    public function the_prompt_asks_for_links_in_the_notes(): void
    {
        $prompt = ExtractionSchema::systemPrompt();

        $this->assertStringContainsString('URL', $prompt);
        $this->assertStringContainsString('verbatim', $prompt);
    }

    #[Test]
    public function the_prompt_says_a_form_is_the_parents_task(): void
    {
        $this->assertStringContainsString(
            'consent form is the parent',
            ExtractionSchema::systemPrompt(),
        );
    }

    #[Test]
    public function household_context_costs_no_extra_queries_per_member(): void
    {
        Member::factory()->count(5)->create(['household_id' => $this->household->id]);

        $capture = $this->capture->fresh()->load([
            'household.members.aliases', 'household.places.aliases', 'household.places.members',
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->extractor()->extract($capture);
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, 'The context should come from eager-loaded relations.');
    }
}
