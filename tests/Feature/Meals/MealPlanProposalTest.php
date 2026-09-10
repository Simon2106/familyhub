<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\MealPlanProposal;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Assistant\AssistantTools;
use App\Services\Meals\MealPlanProposer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one thing the assistant can write, and everything that stops it.
 *
 * A proposal is not a plan. The guards below are the whole reason a language
 * model is allowed anywhere near the planner: whatever it returns, nothing
 * reaches a Thursday until somebody taps Keep.
 */
class MealPlanProposalTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    /** Wednesday, so "already gone" has something to mean. */
    protected const TODAY = '2026-09-09 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::TODAY);

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function proposer(): MealPlanProposer
    {
        return app(MealPlanProposer::class);
    }

    protected function nextWeek(): CarbonImmutable
    {
        return $this->household->weekStart()->addWeek();
    }

    #[Test]
    public function a_proposed_week_writes_no_meals_at_all(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-14', 'title' => 'Leek and potato soup', 'why' => 'something light'],
            ['on' => '2026-09-15', 'title' => 'Fish pie'],
        ]);

        $this->assertNotNull($result['proposal']);
        $this->assertCount(2, $result['proposal']->entryList());

        // The whole guarantee, in one assertion.
        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function a_night_already_planned_is_left_alone(): void
    {
        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-14',
            'slot' => 'dinner',
            'title' => 'Nanny’s',
        ]);

        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-14', 'title' => 'Fish pie'],
            ['on' => '2026-09-15', 'title' => 'Chilli'],
        ]);

        $this->assertSame(['2026-09-15'], $result['kept']);
        $this->assertStringContainsString('already planned', implode(' ', $result['skipped']));

        // And the decision the family made is untouched.
        $this->assertSame('Nanny’s', Meal::first()->title);
    }

    /**
     * Wednesday is no time to be told what to have on Monday.
     */
    #[Test]
    public function nights_that_have_already_gone_are_dropped(): void
    {
        $result = $this->proposer()->propose($this->household, $this->household->weekStart(), [
            ['on' => '2026-09-07', 'title' => 'Monday, gone'],
            ['on' => '2026-09-08', 'title' => 'Tuesday, gone'],
            ['on' => '2026-09-09', 'title' => 'Tonight'],
            ['on' => '2026-09-11', 'title' => 'Friday'],
        ]);

        $this->assertSame(['2026-09-09', '2026-09-11'], $result['kept']);
        $this->assertStringContainsString('already gone', implode(' ', $result['skipped']));
    }

    #[Test]
    public function a_night_outside_the_week_is_refused(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-10-30', 'title' => 'Some other week entirely'],
            ['on' => 'next tuesday', 'title' => 'Not even a date'],
            ['on' => '2026-09-16', 'title' => 'Wednesday'],
        ]);

        $this->assertSame(['2026-09-16'], $result['kept']);
    }

    #[Test]
    public function the_same_night_twice_only_counts_once(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Chilli'],
            ['on' => '2026-09-16', 'title' => 'Also chilli'],
        ]);

        $this->assertSame(['2026-09-16'], $result['kept']);
        $this->assertSame('Chilli', $result['proposal']->entryList()[0]['title']);
    }

    #[Test]
    public function a_week_is_seven_nights_at_the_very_most(): void
    {
        $entries = [];

        for ($i = 0; $i < 7; $i++) {
            $entries[] = ['on' => $this->nextWeek()->addDays($i)->toDateString(), 'title' => 'Dinner '.$i];
        }

        // A model that returns a fortnight has misread the question.
        $entries[] = ['on' => $this->nextWeek()->toDateString(), 'title' => 'An eighth'];

        $result = $this->proposer()->propose($this->household, $this->nextWeek(), $entries);

        $this->assertCount(7, $result['proposal']->entryList());
    }

    #[Test]
    public function a_recipe_belonging_to_another_household_is_never_pointed_at(): void
    {
        $theirs = Recipe::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'title' => 'Their fish pie',
        ]);

        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Fish pie', 'recipe_id' => $theirs->id],
        ]);

        $this->assertNull($result['proposal']->entryList()[0]['recipe_id']);
    }

    #[Test]
    public function an_idea_the_family_already_has_is_matched_rather_than_copied(): void
    {
        $ours = Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Fish pie',
            'status' => 'ready',
        ]);

        // No id given, and the wrong case: still their own fish pie.
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'fish PIE'],
        ]);

        $this->assertSame($ours->id, $result['proposal']->entryList()[0]['recipe_id']);
    }

    #[Test]
    public function accepting_a_night_writes_the_meal_and_takes_it_out_of_the_proposal(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Chilli'],
            ['on' => '2026-09-17', 'title' => 'Fish pie'],
        ]);

        $meal = $this->proposer()->accept($this->household, $result['proposal'], '2026-09-16');

        $this->assertSame('Chilli', $meal->title);
        $this->assertSame(1, Meal::count());

        $this->assertSame(
            ['2026-09-17'],
            array_column($result['proposal']->fresh()->entryList(), 'on'),
        );
    }

    /**
     * The point of proposing new things: the box grows when one is taken up.
     */
    #[Test]
    public function accepting_something_new_puts_the_idea_in_the_recipe_box(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Leek and potato soup'],
        ]);

        $this->proposer()->accept($this->household, $result['proposal'], '2026-09-16');

        $recipe = Recipe::where('title', 'Leek and potato soup')->first();

        $this->assertNotNull($recipe);
        $this->assertSame('suggested', $recipe->source_kind);
        $this->assertSame($recipe->id, Meal::first()->recipe_id);
    }

    #[Test]
    public function accepting_the_last_night_throws_the_proposal_away(): void
    {
        $result = $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Chilli'],
        ]);

        $this->proposer()->acceptAll($this->household, $result['proposal']);

        $this->assertSame(0, MealPlanProposal::count());
    }

    #[Test]
    public function a_second_proposal_for_the_same_week_replaces_the_first(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'First go'],
        ]);

        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Second go'],
        ]);

        $this->assertSame(1, MealPlanProposal::count());
        $this->assertSame('Second go', MealPlanProposal::first()->entryList()[0]['title']);
    }

    /* ------------------------------ the planner ----------------------- */

    #[Test]
    public function the_planner_draws_a_proposal_as_ghosts_and_says_nothing_is_saved(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Leek and potato soup', 'why' => 'quick, swimming after school'],
        ], note: 'One veggie night, and nothing you had last week.');

        Livewire::test('meals.plan')
            ->call('goToWeek', 1)
            ->assertSee('Nothing is saved yet')
            ->assertSee('One veggie night, and nothing you had last week.')
            ->assertSee('Leek and potato soup')
            ->assertSee('quick, swimming after school');

        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function keeping_a_suggestion_from_the_planner_writes_it(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Chilli'],
            ['on' => '2026-09-17', 'title' => 'Fish pie'],
        ]);

        Livewire::test('meals.plan')
            ->call('goToWeek', 1)
            ->call('acceptProposal', '2026-09-16')
            ->assertOk();

        $this->assertSame(['Chilli'], Meal::pluck('title')->all());

        // The night that was kept is gone from the proposal; the other stays.
        $this->assertSame(
            [['on' => '2026-09-17', 'title' => 'Fish pie']],
            array_map(
                fn (array $e) => ['on' => $e['on'], 'title' => $e['title']],
                MealPlanProposal::first()->entryList(),
            ),
        );
    }

    #[Test]
    public function no_thanks_throws_the_whole_thing_away_without_writing_anything(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Chilli'],
        ]);

        Livewire::test('meals.plan')
            ->call('goToWeek', 1)
            ->call('dismissProposals')
            ->assertDontSee('Nothing is saved yet');

        $this->assertSame(0, MealPlanProposal::count());
        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function a_proposal_for_next_week_never_shows_on_this_one(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Leek and potato soup'],
        ]);

        Livewire::test('meals.plan')->assertDontSee('Leek and potato soup');
    }

    #[Test]
    public function a_night_decided_since_the_proposal_stops_being_a_ghost(): void
    {
        $this->proposer()->propose($this->household, $this->nextWeek(), [
            ['on' => '2026-09-16', 'title' => 'Leek and potato soup'],
        ]);

        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-16',
            'slot' => 'dinner',
            'title' => 'Takeaway',
        ]);

        Livewire::test('meals.plan')
            ->call('goToWeek', 1)
            ->assertSee('Takeaway')
            ->assertDontSee('Leek and potato soup');
    }

    /* ------------------------------ the tool -------------------------- */

    #[Test]
    public function the_tool_reports_back_in_words_the_assistant_can_repeat(): void
    {
        $said = app(AssistantTools::class)->run('propose_meal_plan', [
            'week_start' => $this->nextWeek()->toDateString(),
            'entries' => [
                ['on' => '2026-09-16', 'title' => 'Leek and potato soup', 'why' => 'the veggie night you asked for'],
            ],
            'note' => 'One veggie night.',
        ], $this->household);

        $this->assertStringContainsString('not saved', $said);
        $this->assertStringContainsString('Leek and potato soup', $said);
        $this->assertStringContainsString('the veggie night you asked for', $said);
        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function the_ideas_tool_gives_the_model_what_it_needs_to_choose(): void
    {
        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Fish pie',
            'status' => 'ready',
            'tags' => ['weeknight', 'fish'],
            'is_favourite' => true,
        ]);

        $said = app(AssistantTools::class)->run('meal_ideas', [], $this->household);

        $this->assertStringContainsString('#'.$recipe->id.' Fish pie', $said);
        $this->assertStringContainsString('favourite', $said);
        $this->assertStringContainsString('weeknight/fish', $said);
        $this->assertStringContainsString('never cooked', $said);
    }
}
