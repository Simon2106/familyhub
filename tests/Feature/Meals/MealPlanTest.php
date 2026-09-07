<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "this week" has days either side of today.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function meal(string $on, string $title = 'Leftovers', string $slot = 'dinner', ?Recipe $recipe = null): Meal
    {
        return Meal::create([
            'household_id' => $this->household->id,
            'on' => $on,
            'slot' => $slot,
            'title' => $title,
            'recipe_id' => $recipe?->id,
        ]);
    }

    protected function recipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create($attributes + ['household_id' => $this->household->id]);
    }

    #[Test]
    public function the_week_runs_monday_to_sunday_like_the_calendar(): void
    {
        $days = Livewire::test('meals.plan')->instance()->days;

        $this->assertCount(7, $days);
        $this->assertSame('2026-09-07', $days[0]['date'], 'The week should start on Monday.');
        $this->assertSame('2026-09-13', $days[6]['date']);
        $this->assertTrue($days[2]['is_today']);
    }

    #[Test]
    public function only_dinner_is_shown_until_the_household_asks_for_more(): void
    {
        Livewire::test('meals.plan')
            ->assertSee('Add dinner')
            ->assertDontSee('Breakfast');

        $this->household->setMealSlots(['dinner', 'breakfast']);

        // Household::current() memoises for the request; a second render in
        // production is a second request, so model that rather than reading a
        // stale copy.
        Once::flush();

        Livewire::test('meals.plan')->assertSee('Breakfast');
    }

    #[Test]
    public function meal_slots_are_kept_in_the_order_they_happen(): void
    {
        // However they were stored, the grid must not show dinner above breakfast.
        $this->household->setMealSlots(['dinner', 'breakfast', 'lunch']);

        $this->assertSame(['breakfast', 'lunch', 'dinner'], $this->household->fresh()->mealSlots());
    }

    #[Test]
    public function free_text_is_a_first_class_meal(): void
    {
        Livewire::test('meals.plan')
            ->call('edit', '2026-09-09|dinner')
            ->set('title', "Nanny's")
            ->call('save')
            ->assertSee("Nanny's");

        $this->assertDatabaseHas('meals', ['on' => '2026-09-09', 'slot' => 'dinner', 'title' => "Nanny's", 'recipe_id' => null]);
    }

    #[Test]
    public function a_cell_can_be_filled_from_the_recipe_box(): void
    {
        $recipe = $this->recipe(['title' => 'Chicken and chorizo traybake']);

        Livewire::test('meals.plan')
            ->call('edit', '2026-09-10|dinner')
            ->call('choose', $recipe->id);

        $this->assertDatabaseHas('meals', [
            'on' => '2026-09-10',
            'title' => 'Chicken and chorizo traybake',
            'recipe_id' => $recipe->id,
        ]);
    }

    #[Test]
    public function a_meal_keeps_its_name_when_its_recipe_is_deleted(): void
    {
        $recipe = $this->recipe(['title' => 'Miso salmon']);
        $meal = $this->meal('2026-09-10', 'Miso salmon', recipe: $recipe);

        $recipe->delete();

        $meal->refresh();

        $this->assertSame('Miso salmon', $meal->title);
        $this->assertNull($meal->recipe_id);
    }

    #[Test]
    public function writing_a_second_meal_into_a_cell_replaces_the_first(): void
    {
        $this->meal('2026-09-09', 'Leftovers');

        Livewire::test('meals.plan')
            ->call('edit', '2026-09-09|dinner')
            ->set('title', 'Fish and chips')
            ->call('save');

        // One square, one meal, like the paper planner.
        $this->assertSame(1, Meal::where('on', '2026-09-09')->count());
        $this->assertDatabaseHas('meals', ['on' => '2026-09-09', 'title' => 'Fish and chips']);
    }

    #[Test]
    public function a_meal_can_be_dragged_to_another_day(): void
    {
        $meal = $this->meal('2026-09-09', 'Fish and chips');

        Livewire::test('meals.plan')->call('move', $meal->id, '2026-09-11', 'dinner');

        $this->assertSame('2026-09-11', $meal->fresh()->on->toDateString());
    }

    #[Test]
    public function dropping_onto_a_full_cell_replaces_what_was_there(): void
    {
        $moving = $this->meal('2026-09-09', 'Fish and chips');
        $displaced = $this->meal('2026-09-11', 'Leftovers');

        Livewire::test('meals.plan')->call('move', $moving->id, '2026-09-11', 'dinner');

        $this->assertModelMissing($displaced);
        $this->assertSame('2026-09-11', $moving->fresh()->on->toDateString());
        $this->assertSame(1, Meal::count());
    }

    #[Test]
    public function a_move_outside_the_shown_week_is_refused(): void
    {
        // The cell key comes from the browser, so it is not to be trusted.
        $meal = $this->meal('2026-09-09', 'Fish and chips');

        Livewire::test('meals.plan')->call('move', $meal->id, '2026-12-25', 'dinner');

        $this->assertSame('2026-09-09', $meal->fresh()->on->toDateString());
    }

    #[Test]
    public function a_move_into_a_slot_the_household_does_not_plan_is_refused(): void
    {
        $meal = $this->meal('2026-09-09', 'Fish and chips');

        Livewire::test('meals.plan')->call('move', $meal->id, '2026-09-10', 'breakfast');

        $this->assertSame('dinner', $meal->fresh()->slot);
    }

    #[Test]
    public function an_idea_can_be_dropped_from_the_shelf_onto_a_day(): void
    {
        $recipe = $this->recipe(['title' => 'Miso salmon']);

        Livewire::test('meals.plan')->call('place', $recipe->id, '2026-09-12', 'dinner');

        $this->assertDatabaseHas('meals', [
            'on' => '2026-09-12',
            'title' => 'Miso salmon',
            'recipe_id' => $recipe->id,
        ]);
    }

    #[Test]
    public function the_shelf_holds_ideas_that_are_not_already_planned(): void
    {
        $planned = $this->recipe(['title' => 'Already cooking this']);
        $this->recipe(['title' => 'Still waiting for a day']);
        $this->meal('2026-09-09', 'Already cooking this', recipe: $planned);

        Livewire::test('meals.plan')
            ->assertSee('Still waiting for a day')
            ->assertSee('Unplanned ideas');

        $shelf = Livewire::test('meals.plan')->instance()->shelf->pluck('title')->all();

        $this->assertSame(['Still waiting for a day'], $shelf);
    }

    #[Test]
    public function last_week_can_be_copied_forward(): void
    {
        $this->meal('2026-09-02', 'Fish and chips');
        $this->meal('2026-09-04', 'Curry');

        Livewire::test('meals.plan')->call('copyLastWeek');

        $this->assertDatabaseHas('meals', ['on' => '2026-09-09', 'title' => 'Fish and chips']);
        $this->assertDatabaseHas('meals', ['on' => '2026-09-11', 'title' => 'Curry']);
    }

    #[Test]
    public function copying_last_week_does_not_duplicate_what_is_already_planned(): void
    {
        $this->meal('2026-09-02', 'Fish and chips');
        $this->meal('2026-09-09', 'Out');

        Livewire::test('meals.plan')->call('copyLastWeek');

        $this->assertSame(1, Meal::where('on', '2026-09-09')->count());
        $this->assertDatabaseHas('meals', ['on' => '2026-09-09', 'title' => 'Fish and chips']);
    }

    #[Test]
    public function clearing_the_week_leaves_other_weeks_alone(): void
    {
        $this->meal('2026-09-09', 'Curry');
        $this->meal('2026-09-16', 'Toad in the hole');

        Livewire::test('meals.plan')->call('clearWeek');

        $this->assertDatabaseMissing('meals', ['title' => 'Curry']);
        $this->assertDatabaseHas('meals', ['title' => 'Toad in the hole']);
    }

    #[Test]
    public function the_grid_can_be_moved_a_week_at_a_time(): void
    {
        // Not "Next week": the navigation button is labelled that.
        $this->meal('2026-09-16', 'Toad in the hole');

        Livewire::test('meals.plan')
            ->assertDontSee('Toad in the hole')
            ->call('goToWeek', 1)
            ->assertSee('Toad in the hole');
    }

    #[Test]
    public function the_wall_says_what_is_for_dinner_tonight(): void
    {
        $this->meal('2026-09-09', 'Fish and chips');

        Livewire::test('display.wall')
            ->assertSee('Tonight')
            ->assertSee('Fish and chips');
    }

    #[Test]
    public function the_phone_home_says_it_too(): void
    {
        $this->meal('2026-09-09', 'Fish and chips');

        Livewire::test('phone.home')->assertSee('Tonight: Fish and chips');
    }

    #[Test]
    public function the_week_grid_does_not_query_once_per_day(): void
    {
        foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $date) {
            $this->meal($date, 'Something');
        }

        $measure = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::test('meals.plan');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Warm-up: the first render resolves the household.
        $measure();
        $withThree = $measure();

        foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'] as $date) {
            $this->meal($date, 'Something else');
        }

        $this->assertSame($withThree, $measure(), 'The grid should read the week in one query.');
    }
}
