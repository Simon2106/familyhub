<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Meals\MealFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Suggesting into the planner, without ever writing behind the family's back. */
class PlannerSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday; the week runs Mon 7 to Sun 13 September.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id]);
        $this->actingAs(User::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->simon->id,
        ]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function idea(string $title, array $attributes = []): Recipe
    {
        return Recipe::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'title' => $title,
            'status' => 'ready',
            'tags' => [],
        ]);
    }

    /* ---------------------------- fill the week -------------------------- */

    #[Test]
    public function filling_the_week_proposes_and_writes_nothing(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $title) {
            $this->idea($title);
        }

        $component = Livewire::test('meals.plan')->call('suggestWeek');

        // Wednesday: Monday and Tuesday have been and gone, so five nights.
        $this->assertCount(5, $component->get('proposals'));
        $this->assertArrayNotHasKey('2026-09-07', $component->get('proposals'));
        $this->assertSame(0, Meal::count(), 'A proposal is not a plan.');
        $component->assertSee('Nothing is saved yet');
    }

    #[Test]
    public function a_proposal_is_kept_one_night_at_a_time(): void
    {
        $this->idea('Fish pie');

        $component = Livewire::test('meals.plan')->call('suggestWeek');

        $night = array_key_first($component->get('proposals'));

        $component->call('acceptProposal', $night);

        $this->assertSame(1, Meal::count());
        $this->assertSame($night, Meal::first()->on->toDateString());
        $this->assertArrayNotHasKey($night, $component->get('proposals'));
    }

    #[Test]
    public function a_whole_week_can_be_kept_at_once(): void
    {
        foreach (['A', 'B', 'C'] as $title) {
            $this->idea($title);
        }

        Livewire::test('meals.plan')
            ->call('suggestWeek')
            ->call('acceptAllProposals')
            ->assertSet('proposals', []);

        $this->assertSame(3, Meal::count());
        foreach (Meal::pluck('on') as $on) {
            $this->assertGreaterThanOrEqual('2026-09-09', $on->toDateString());
        }
    }

    #[Test]
    public function no_thanks_leaves_the_week_exactly_as_it_was(): void
    {
        $this->idea('Fish pie');

        Livewire::test('meals.plan')
            ->call('suggestWeek')
            ->call('dismissProposals')
            ->assertSet('proposals', []);

        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function a_proposal_does_not_follow_you_into_next_week(): void
    {
        // Otherwise next week's Thursday lands on this one.
        $this->idea('Fish pie');

        Livewire::test('meals.plan')
            ->call('suggestWeek')
            ->call('goToWeek', 1)
            ->assertSet('proposals', []);
    }

    #[Test]
    public function a_night_already_planned_is_left_alone(): void
    {
        $planned = $this->idea('Already decided');
        Meal::factory()->create([
            'household_id' => $this->household->id,
            'on' => '2026-09-10', 'slot' => 'dinner',
            'title' => $planned->title, 'recipe_id' => $planned->id,
        ]);

        $this->idea('A suggestion');

        $proposals = Livewire::test('meals.plan')->call('suggestWeek')->get('proposals');

        $this->assertArrayNotHasKey('2026-09-10', $proposals);
    }

    /* ----------------------------- surprise me --------------------------- */

    #[Test]
    public function surprise_me_fills_the_night_being_edited(): void
    {
        $this->idea('Fish pie');

        Livewire::test('meals.plan')
            ->call('edit', '2026-09-10|dinner')
            ->call('surprise');

        $this->assertSame('Fish pie', Meal::first()->title);
        $this->assertNotNull(Meal::first()->recipe_id, 'And it stays linked to the idea.');
    }

    #[Test]
    public function surprise_me_with_an_empty_box_says_so(): void
    {
        Livewire::test('meals.plan')
            ->call('edit', '2026-09-10|dinner')
            ->call('surprise')
            ->assertHasErrors('title');

        $this->assertSame(0, Meal::count());
    }

    #[Test]
    public function a_surprise_avoids_what_was_marked_down_and_what_was_just_eaten(): void
    {
        $disliked = $this->idea('Liver');
        $recent = $this->idea('Curry');
        $good = $this->idea('Fish pie');

        app(MealFeedback::class)->star($disliked, $this->simon, 2);

        Meal::factory()->create([
            'household_id' => $this->household->id,
            'on' => '2026-09-05', 'slot' => 'dinner',
            'title' => 'Curry', 'recipe_id' => $recent->id,
        ]);

        Livewire::test('meals.plan')
            ->call('edit', '2026-09-11|dinner')
            ->call('surprise');

        $this->assertSame('Fish pie', Meal::where('on', '2026-09-11')->first()->title);
    }

    /* ------------------------------ the picker --------------------------- */

    #[Test]
    public function the_picker_offers_the_shelf_a_family_reaches_for(): void
    {
        $ages = $this->idea('Chilli');
        $recent = $this->idea('Curry');

        foreach ([['2026-07-01', $ages], ['2026-09-05', $recent]] as [$on, $idea]) {
            Meal::factory()->create([
                'household_id' => $this->household->id,
                'on' => $on, 'slot' => 'dinner',
                'title' => $idea->title, 'recipe_id' => $idea->id,
            ]);
        }

        $titles = Livewire::test('meals.plan')
            ->call('edit', '2026-09-10|dinner')
            ->set('picking', 'while')
            ->instance()->pickable->pluck('title')->all();

        $this->assertContains('Chilli', $titles);
        $this->assertNotContains('Curry', $titles);
    }
}
