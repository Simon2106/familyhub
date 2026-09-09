<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\MealCollection;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Services\Meals\MealFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** An idea, what the family thinks of it, and how often they have had it. */
class MealIdeaTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected Member $jenna;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $this->jenna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna']);
        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function idea(array $attributes = []): Recipe
    {
        return Recipe::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'status' => 'ready',
        ]);
    }

    protected function ate(Recipe|string $what, string $on): Meal
    {
        return Meal::factory()->create([
            'household_id' => $this->household->id,
            'on' => $on,
            'slot' => 'dinner',
            'title' => $what instanceof Recipe ? $what->title : $what,
            'recipe_id' => $what instanceof Recipe ? $what->id : null,
        ]);
    }

    protected function loaded(Recipe $recipe): Recipe
    {
        return Recipe::query()
            ->withCookedHistory('2026-09-09')
            ->withOpinions()
            ->findOrFail($recipe->id);
    }

    /* ------------------------------ opinions ----------------------------- */

    #[Test]
    public function the_adults_stars_are_averaged(): void
    {
        $idea = $this->idea(['title' => 'Fish pie']);

        app(MealFeedback::class)->star($idea, $this->simon, 5);
        app(MealFeedback::class)->star($idea, $this->jenna, 4);

        $this->assertSame(4.5, $this->loaded($idea)->stars());
        $this->assertSame(2, (int) $this->loaded($idea)->stars_count);
    }

    #[Test]
    public function rating_twice_replaces_your_own_rating_rather_than_adding_one(): void
    {
        // "One per adult" is the promise; a second helping should not count as
        // a second vote.
        $idea = $this->idea();

        app(MealFeedback::class)->star($idea, $this->simon, 2);
        app(MealFeedback::class)->star($idea, $this->simon, 5);

        $this->assertSame(1, RecipeRating::where('recipe_id', $idea->id)->count());
        $this->assertSame(5.0, $this->loaded($idea)->stars());
    }

    #[Test]
    public function stars_are_kept_inside_one_to_five(): void
    {
        $idea = $this->idea();

        $this->assertSame(5, app(MealFeedback::class)->star($idea, $this->simon, 9)->stars);
        $this->assertSame(1, app(MealFeedback::class)->star($idea, $this->jenna, 0)->stars);
    }

    #[Test]
    public function the_children_vote_separately_from_the_adults(): void
    {
        // A meal the children love and the adults are tired of is a real thing
        // to be able to see.
        $idea = $this->idea();

        app(MealFeedback::class)->star($idea, $this->simon, 2);
        app(MealFeedback::class)->thumb($idea, $this->joey, 1);

        $loaded = $this->loaded($idea);

        $this->assertSame(2.0, $loaded->stars(), 'A thumb is not a star.');
        $this->assertSame(1, (int) $loaded->stars_count);
        $this->assertSame('up', $loaded->kidsVerdict());
    }

    #[Test]
    public function the_same_thumb_twice_takes_it_back(): void
    {
        // No PIN, no undo, and a wall anybody walks past: the way out of a
        // mis-tap has to be the same tap.
        $idea = $this->idea();

        app(MealFeedback::class)->thumb($idea, $this->joey, 1);
        app(MealFeedback::class)->thumb($idea, $this->joey, 1);

        $this->assertNull($this->loaded($idea)->kidsVerdict());
    }

    #[Test]
    public function changing_your_mind_flips_the_thumb(): void
    {
        $idea = $this->idea();

        app(MealFeedback::class)->thumb($idea, $this->joey, 1);
        app(MealFeedback::class)->thumb($idea, $this->joey, -1);

        $this->assertSame('down', $this->loaded($idea)->kidsVerdict());
    }

    /* ------------------------------ history ------------------------------ */

    #[Test]
    public function a_day_passing_with_an_idea_in_it_counts_as_having_cooked_it(): void
    {
        $idea = $this->idea(['title' => 'Fish pie']);

        $this->ate($idea, '2026-08-20');
        $this->ate($idea, '2026-09-02');
        // Tonight has not happened yet.
        $this->ate($idea, '2026-09-09');

        $loaded = $this->loaded($idea);

        $this->assertSame(2, $loaded->timesCooked());
        $this->assertSame('2026-09-02', $loaded->lastCooked()?->toDateString());
    }

    #[Test]
    public function an_idea_nobody_has_cooked_says_so(): void
    {
        $loaded = $this->loaded($this->idea());

        $this->assertTrue($loaded->neverCooked());
        $this->assertNull($loaded->lastCooked());
    }

    #[Test]
    public function history_is_read_off_the_planner_rather_than_counted_up(): void
    {
        // Nothing records "we ate this" — the day passing is the record — so a
        // meal deleted from the plan has to stop counting.
        $idea = $this->idea();
        $meal = $this->ate($idea, '2026-08-20');

        $this->assertSame(1, $this->loaded($idea)->timesCooked());

        $meal->delete();

        $this->assertSame(0, $this->loaded($idea)->timesCooked());
    }

    /* ----------------------------- promotion ----------------------------- */

    #[Test]
    public function a_typed_in_meal_becomes_an_idea_without_losing_its_history(): void
    {
        // "fajitas", typed straight into the planner four times before anyone
        // thought to save it.
        foreach (['2026-07-10', '2026-07-24', '2026-08-14'] as $on) {
            $this->ate('Fajitas', $on);
        }

        $latest = $this->ate('Fajitas', '2026-09-04');

        $idea = app(MealFeedback::class)->rate($latest, $this->simon, 5, 'Joey won\'t eat the sauce');

        $this->assertSame('Fajitas', $idea->title);
        $this->assertSame('ready', $idea->status);
        $this->assertSame(4, $this->loaded($idea)->timesCooked(), 'Every fajita night it ever had.');
        $this->assertSame(5.0, $this->loaded($idea)->stars());
        $this->assertNotNull($latest->fresh()->feedback_at);
    }

    #[Test]
    public function promoting_a_meal_that_already_has_an_idea_reuses_it(): void
    {
        $existing = $this->idea(['title' => 'Fajitas']);
        $meal = $this->ate('fajitas', '2026-09-04');

        $this->assertSame($existing->id, app(MealFeedback::class)->promote($meal)->id);
        $this->assertSame(1, Recipe::where('title', 'like', 'fajitas')->count());
    }

    /* ------------------------------ asking ------------------------------- */

    #[Test]
    public function the_most_recent_unasked_dinner_is_the_one_to_ask_about(): void
    {
        $this->ate('Fish pie', '2026-09-07');
        $last = $this->ate('Fajitas', '2026-09-08');
        $this->ate('Curry', '2026-09-09');

        $this->assertSame($last->id, app(MealFeedback::class)->awaiting($this->household)?->id);
    }

    #[Test]
    public function a_meal_already_asked_about_is_never_asked_about_again(): void
    {
        // A prompt that reappears is one people learn to swipe past.
        $meal = $this->ate('Fish pie', '2026-09-08');

        app(MealFeedback::class)->dismiss($meal);

        $this->assertNull(app(MealFeedback::class)->awaiting($this->household));
    }

    #[Test]
    public function last_tuesday_is_nobody_s_memory(): void
    {
        $this->ate('Fish pie', '2026-08-30');

        $this->assertNull(app(MealFeedback::class)->awaiting($this->household));
    }

    /* ---------------------------- collections ---------------------------- */

    #[Test]
    public function ideas_can_be_gathered_into_a_pile_somebody_made(): void
    {
        $collection = MealCollection::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Freezer standbys',
        ]);

        $idea = $this->idea(['title' => 'Chilli']);
        $collection->recipes()->attach($idea->id);

        $this->assertSame('Chilli', $collection->recipes()->first()->title);
        $this->assertSame('Freezer standbys', $idea->collections()->first()->name);
    }

    #[Test]
    public function a_tag_can_be_searched_for_the_same_way_on_both_databases(): void
    {
        // JSON containment differs between MySQL and SQLite; this is the one
        // shape that behaves identically on each.
        $this->idea(['title' => 'Chilli', 'tags' => ['batch', 'freezer']]);
        $this->idea(['title' => 'Salad', 'tags' => ['quick']]);

        $this->assertSame(['Chilli'], Recipe::tagged('batch')->pluck('title')->all());
        $this->assertSame([], Recipe::tagged('fakeaway')->pluck('title')->all());
    }
}
