<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Asking how it was, and the children's verdict on the wall. */
class HowWasItTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected Member $joey;

    protected Member $sienna;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
        $this->sienna = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Sienna', 'is_child' => true,
        ]);

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

    protected function ate(string $title, string $on, ?Recipe $idea = null): Meal
    {
        return Meal::factory()->create([
            'household_id' => $this->household->id,
            'on' => $on, 'slot' => 'dinner',
            'title' => $title, 'recipe_id' => $idea?->id,
        ]);
    }

    /* ------------------------------ how was it --------------------------- */

    #[Test]
    public function last_night_is_asked_about_once(): void
    {
        $this->ate('Fish pie', '2026-09-08');

        Livewire::test('meals.how-was-it')
            ->assertSee('How was Fish pie?')
            ->call('rate', 4);

        $this->assertSame(4, RecipeRating::first()->stars);

        // And never again.
        Livewire::test('meals.how-was-it')->assertDontSee('How was');
    }

    #[Test]
    public function waving_it_away_counts_as_answering(): void
    {
        // A prompt that reappears is one people learn to swipe past.
        $this->ate('Fish pie', '2026-09-08');

        Livewire::test('meals.how-was-it')->call('notNow');

        Livewire::test('meals.how-was-it')->assertDontSee('How was');
        $this->assertSame(0, RecipeRating::count());
    }

    #[Test]
    public function tonight_is_not_asked_about_because_it_has_not_happened(): void
    {
        $this->ate('Fish pie', '2026-09-09');

        Livewire::test('meals.how-was-it')->assertDontSee('How was');
    }

    #[Test]
    public function rating_a_typed_in_meal_makes_it_an_idea(): void
    {
        $this->ate('Fajitas', '2026-07-10');
        $this->ate('Fajitas', '2026-09-08');

        Livewire::test('meals.how-was-it')
            ->set('noting', true)
            ->set('note', 'Double the peppers')
            ->call('rate', 5);

        $idea = Recipe::firstWhere('title', 'Fajitas');

        $this->assertNotNull($idea);
        $this->assertSame(2, $idea->timesCooked(), 'Both fajita nights came with it.');
        $this->assertSame('Double the peppers', RecipeRating::first()->note);
    }

    /* -------------------------- the wall's thumbs ------------------------ */

    #[Test]
    public function a_child_can_say_what_they_thought_without_a_pin(): void
    {
        $idea = Recipe::factory()->create([
            'household_id' => $this->household->id, 'title' => 'Fish pie', 'status' => 'ready',
        ]);
        $this->ate('Fish pie', '2026-09-09', $idea);

        Livewire::test('meals.tonight')
            ->assertSee('Fish pie')
            ->assertSee('Joey')
            ->assertSee('Sienna')
            ->call('thumb', $this->joey->id, 1);

        $this->assertSame(1, RecipeRating::where('member_id', $this->joey->id)->first()->thumbs);
    }

    #[Test]
    public function the_same_thumb_again_takes_it_back(): void
    {
        // The only undo a wall can offer.
        $idea = Recipe::factory()->create([
            'household_id' => $this->household->id, 'title' => 'Fish pie', 'status' => 'ready',
        ]);
        $this->ate('Fish pie', '2026-09-09', $idea);

        Livewire::test('meals.tonight')
            ->call('thumb', $this->joey->id, 1)
            ->call('thumb', $this->joey->id, 1);

        $this->assertSame(0, RecipeRating::count());
    }

    #[Test]
    public function a_thumb_on_a_typed_in_dinner_promotes_it(): void
    {
        // The moment it earns being an idea: somebody has an opinion about it.
        $this->ate('Fajitas', '2026-09-09');

        Livewire::test('meals.tonight')->call('thumb', $this->sienna->id, -1);

        $idea = Recipe::firstWhere('title', 'Fajitas');

        $this->assertNotNull($idea);
        $this->assertSame('down', Recipe::withOpinions()->find($idea->id)->kidsVerdict());
    }

    #[Test]
    public function an_adult_is_not_offered_a_thumb(): void
    {
        $this->ate('Fish pie', '2026-09-09');

        Livewire::test('meals.tonight')->assertDontSee('Simon');
    }

    #[Test]
    public function nothing_planned_means_nothing_to_vote_on(): void
    {
        Livewire::test('meals.tonight')->assertDontSee('Tonight');
    }
}
