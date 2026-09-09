<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Services\Meals\MealFeedback;
use App\Services\Meals\MealSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suggesting a dinner for a night nobody has decided about.
 *
 * Everything here proposes; nothing here writes.
 */
class MealSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday. The week starts Monday 7 September.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id]);
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
        ]);
    }

    protected function ate(Recipe $idea, string $on): Meal
    {
        return Meal::factory()->create([
            'household_id' => $this->household->id,
            'on' => $on, 'slot' => 'dinner',
            'title' => $idea->title, 'recipe_id' => $idea->id,
        ]);
    }

    protected function suggester(): MealSuggester
    {
        return app(MealSuggester::class);
    }

    protected function monday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-07', 'Europe/London');
    }

    /* ---------------------------- surprise me ---------------------------- */

    #[Test]
    public function a_surprise_is_something_nobody_has_marked_down(): void
    {
        $liked = $this->idea('Fish pie');
        $disliked = $this->idea('Liver');

        app(MealFeedback::class)->star($liked, $this->simon, 5);
        app(MealFeedback::class)->star($disliked, $this->simon, 2);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('Fish pie', $this->suggester()->surprise($this->household, $this->monday())?->title);
        }
    }

    #[Test]
    public function something_eaten_in_the_last_three_weeks_gets_a_rest(): void
    {
        $recent = $this->idea('Fish pie');
        $rested = $this->idea('Chilli');

        $this->ate($recent, '2026-09-02');
        $this->ate($rested, '2026-07-01');

        $this->assertSame('Chilli', $this->suggester()->surprise($this->household, $this->monday())?->title);
    }

    #[Test]
    public function an_idea_nobody_has_rated_is_still_worth_suggesting(): void
    {
        // A box where only the scored things can be suggested never suggests
        // anything new.
        $this->idea('Something new');

        $this->assertSame('Something new', $this->suggester()->surprise($this->household, $this->monday())?->title);
    }

    #[Test]
    public function nothing_to_suggest_is_answered_with_nothing(): void
    {
        $this->assertNull($this->suggester()->surprise($this->household, $this->monday()));
    }

    #[Test]
    public function friday_leans_towards_a_fakeaway(): void
    {
        $this->idea('Roast chicken', ['tags' => ['weekend']]);
        $this->idea('Katsu curry', ['tags' => ['fakeaway']]);

        $friday = CarbonImmutable::parse('2026-09-11', 'Europe/London');

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('Katsu curry', $this->suggester()->surprise($this->household, $friday)?->title);
        }
    }

    #[Test]
    public function a_leaning_is_a_preference_and_not_a_requirement(): void
    {
        // A week where nothing carries the right tag still gets filled.
        $this->idea('Roast chicken', ['tags' => ['weekend']]);

        $friday = CarbonImmutable::parse('2026-09-11', 'Europe/London');

        $this->assertSame('Roast chicken', $this->suggester()->surprise($this->household, $friday)?->title);
    }

    /* ---------------------------- fill the week -------------------------- */

    #[Test]
    public function a_week_is_a_different_dinner_every_night(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $title) {
            $this->idea($title);
        }

        // Next week, so all seven nights are still to come.
        $week = $this->suggester()->week($this->household, $this->monday()->addWeek());

        $this->assertCount(7, $week);
        $this->assertCount(7, $week->pluck('id')->unique(), 'The same favourite four times is not a plan.');
    }

    #[Test]
    public function a_night_that_has_already_been_is_not_proposed(): void
    {
        // Wednesday is no time to be told what to have on Monday.
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $title) {
            $this->idea($title);
        }

        $week = $this->suggester()->week($this->household, $this->monday());

        $this->assertSame(
            ['2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'],
            $week->keys()->all(),
        );
    }

    #[Test]
    public function a_night_already_planned_is_not_a_gap(): void
    {
        $planned = $this->idea('Already decided');
        $this->ate($planned, '2026-09-10');

        foreach (['A', 'B', 'C'] as $title) {
            $this->idea($title);
        }

        $week = $this->suggester()->week($this->household, $this->monday());

        $this->assertArrayNotHasKey('2026-09-10', $week->all());
    }

    #[Test]
    public function a_short_box_fills_what_it_can_and_stops(): void
    {
        $this->idea('The only thing we have');

        $this->assertCount(1, $this->suggester()->week($this->household, $this->monday()));
    }

    #[Test]
    public function suggesting_a_week_writes_nothing(): void
    {
        // The planner shows a proposal until somebody accepts it. A planner
        // that filled itself in is one the family stops trusting.
        foreach (['A', 'B', 'C'] as $title) {
            $this->idea($title);
        }

        $this->suggester()->week($this->household, $this->monday());

        $this->assertSame(0, Meal::count());
    }
}
