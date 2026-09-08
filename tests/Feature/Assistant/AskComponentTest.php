<?php

namespace Tests\Feature\Assistant;

use App\Models\Household;
use App\Models\User;
use App\Services\Assistant\Contracts\Assistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAssistant;
use Tests\TestCase;

/** The box on /app: asking, following up, and being told when it went wrong. */
class AskComponentTest extends TestCase
{
    use RefreshDatabase;

    protected FakeAssistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $household->id]));

        $this->assistant = new FakeAssistant;
        $this->app->instance(Assistant::class, $this->assistant);
    }

    #[Test]
    public function it_asks_and_shows_the_answer(): void
    {
        $this->assistant->queue('Fish pie, from this week’s meal plan.', ['meals']);

        Livewire::test('assistant.ask')
            ->set('question', 'What is for tea?')
            ->call('ask')
            ->assertSee('Fish pie')
            ->assertSee('Looked at the meal plan')
            // The box empties, because the next question is a new one.
            ->assertSet('question', '');

        $this->assertSame('What is for tea?', $this->assistant->asked[0]['question']);
    }

    #[Test]
    public function the_page_says_it_cannot_change_anything(): void
    {
        Livewire::test('assistant.ask')->assertSee('never adds, changes or deletes');
    }

    #[Test]
    public function a_follow_up_carries_the_conversation_with_it(): void
    {
        $this->assistant->queue('Fish pie on Thursday.')->queue('Nothing planned yet.');

        Livewire::test('assistant.ask')
            ->set('question', 'What is for tea this week?')
            ->call('ask')
            ->set('question', 'And the week after?')
            ->call('ask');

        $history = $this->assistant->asked[1]['history'];

        $this->assertCount(2, $history);
        $this->assertSame('What is for tea this week?', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame('Fish pie on Thursday.', $history[1]['content']);
    }

    #[Test]
    public function only_the_last_few_exchanges_are_carried(): void
    {
        $component = Livewire::test('assistant.ask');

        for ($i = 0; $i < 6; $i++) {
            $this->assistant->queue('Answer '.$i);
            $component->set('question', 'Question '.$i)->call('ask');
        }

        $history = end($this->assistant->asked)['history'];

        $this->assertLessThanOrEqual(6, count($history));
        $this->assertSame('Question 5', end($this->assistant->asked)['question']);
    }

    #[Test]
    public function nothing_survives_starting_again(): void
    {
        $this->assistant->queue('Fish pie.');

        Livewire::test('assistant.ask')
            ->set('question', 'What is for tea?')
            ->call('ask')
            ->assertSee('Fish pie')
            ->call('startAgain')
            ->assertDontSee('Fish pie')
            ->assertSet('exchanges', []);
    }

    #[Test]
    public function a_failure_is_shown_and_the_question_is_kept(): void
    {
        // Retyping it is the rudest possible answer to "that did not work".
        $this->assistant->throw = 'Claude declined to answer that.';

        Livewire::test('assistant.ask')
            ->set('question', 'What is on?')
            ->call('ask')
            ->assertSee('Claude declined to answer that.')
            ->assertSee('What is on?');
    }

    #[Test]
    public function an_empty_question_is_not_asked(): void
    {
        Livewire::test('assistant.ask')
            ->set('question', '   ')
            ->call('ask')
            ->assertSet('exchanges', []);

        $this->assertSame([], $this->assistant->asked);
    }

    #[Test]
    public function the_box_is_on_the_phone_home_page(): void
    {
        $this->get('/app')->assertSee('Ask about the household', false);
    }
}
