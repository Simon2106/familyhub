<?php

namespace Tests\Feature\Recipes;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\Timer;
use App\Models\User;
use App\Services\Recipes\StepSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Cooking from the wall, with floury hands. */
class CookModeTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);
    }

    protected function recipe(array $attributes = []): Recipe
    {
        return Recipe::create($attributes + [
            'household_id' => $this->household->id,
            'title' => 'Chilli',
        ]);
    }

    /* ---------------------------- the steps ----------------------------- */

    #[Test]
    public function a_numbered_list_is_taken_at_its_word(): void
    {
        $steps = app(StepSplitter::class)->split(<<<'TEXT'
            Ingredients: beef, beans, tomatoes

            1. Brown the mince in a wide pan.
            2) Add the onions and cook for ten minutes.
            Step 3: Tip in the tomatoes and simmer for 40 minutes.
            TEXT);

        $this->assertCount(3, $steps);
        $this->assertSame('Brown the mince in a wide pan.', $steps[0]);
        $this->assertStringContainsString('simmer for 40 minutes', $steps[2]);
    }

    #[Test]
    public function a_wall_of_text_is_split_into_sentences(): void
    {
        // Half of what lands in the box is an Instagram caption.
        $steps = app(StepSplitter::class)->split(
            'Brown the mince in a wide pan. Add the onions and soften them. Tip in the tomatoes and simmer for forty minutes.'
        );

        $this->assertCount(3, $steps);
        $this->assertSame('Brown the mince in a wide pan.', $steps[0]);
    }

    #[Test]
    public function the_split_is_done_once_and_written_back(): void
    {
        $recipe = $this->recipe([
            'raw_text' => "1. Brown the mince well.\n2. Add the onions and soften.",
        ]);

        $this->assertNull($recipe->steps);

        $steps = app(StepSplitter::class)->for($recipe);

        $this->assertCount(2, $steps);
        $this->assertCount(2, (array) $recipe->fresh()->steps, 'It should not be worked out again next time.');
    }

    #[Test]
    public function a_recipe_that_already_has_steps_is_left_alone(): void
    {
        $recipe = $this->recipe([
            'steps' => ['Do the thing properly.'],
            'raw_text' => '1. Something else entirely that should not win.',
        ]);

        $this->assertSame(['Do the thing properly.'], app(StepSplitter::class)->for($recipe));
    }

    #[Test]
    public function a_recipe_with_nothing_to_split_says_so_rather_than_inventing(): void
    {
        $this->assertSame([], app(StepSplitter::class)->for($this->recipe()));
    }

    #[Test]
    public function cook_mode_opens_a_recipe_that_never_had_structured_steps(): void
    {
        // This is the whole reason the splitter exists: cook mode shows one
        // step a screen, so a recipe with none is one it cannot open.
        $recipe = $this->recipe([
            'raw_text' => "1. Brown the mince well.\n2. Add the onions.\n3. Simmer for 30 minutes.",
        ]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertSee('Brown the mince well.');
    }

    /* --------------------------- the servings --------------------------- */

    #[Test]
    public function quantities_scale_with_the_number_eating(): void
    {
        $recipe = $this->recipe([
            'servings' => 4,
            'ingredients' => [
                ['item' => 'beef mince', 'quantity' => 500, 'unit' => 'g'],
                ['item' => 'onion', 'quantity' => 2, 'unit' => null],
                // No quantity: "a pinch" does not double.
                ['item' => 'salt', 'quantity' => null, 'unit' => null, 'note' => 'to taste'],
            ],
            'steps' => ['Cook it.'],
        ]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertSee('500 g beef mince')
            ->call('scaleTo', 8)
            ->assertSee('1000 g beef mince')
            ->assertSee('4 onion')
            ->assertSee('salt')
            ->assertSee('written for 4');
    }

    #[Test]
    public function a_recipe_that_never_said_how_many_it_serves_is_not_scaled(): void
    {
        // Doubling a recipe written for nobody-knows-how-many is arithmetic
        // dressed up as help.
        $recipe = $this->recipe([
            'ingredients' => [['item' => 'beef mince', 'quantity' => 500, 'unit' => 'g']],
            'steps' => ['Cook it.'],
        ]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertSet('canScale', false)
            ->assertDontSee('Cooking for');
    }

    #[Test]
    public function awkward_fractions_are_rounded_to_something_a_scale_can_read(): void
    {
        $recipe = $this->recipe([
            'servings' => 3,
            'ingredients' => [['item' => 'flour', 'quantity' => 100, 'unit' => 'g']],
            'steps' => ['Cook it.'],
        ]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->call('scaleTo', 2)
            ->assertSee('67 g flour');
    }

    /* ------------------------- holding the screen ------------------------ */

    #[Test]
    public function cooking_holds_the_screensaver_back_and_gives_it_up_afterwards(): void
    {
        // A photograph over step four is something somebody has to wipe away
        // with floury hands.
        $recipe = $this->recipe(['steps' => ['Cook it.']]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertDispatched('cooking-started')
            ->call('close')
            ->assertDispatched('cooking-ended');
    }

    #[Test]
    public function the_minutes_in_a_step_are_offered_as_a_timer(): void
    {
        $recipe = $this->recipe(['steps' => ['Simmer for 20-25 minutes until thickened.']]);

        $cook = Livewire::test('recipes.cook')->call('start', $recipe->id);

        // The longer of a range: a timer that goes off early is a timer
        // somebody has to set again.
        $this->assertSame([25], $cook->instance()->minutesIn('Simmer for 20-25 minutes until thickened.'));
    }

    #[Test]
    public function a_timer_started_at_the_hob_outlives_the_cooking(): void
    {
        Livewire::test('display.timers')->call('startFor', 25, 'Chilli');

        $timer = Timer::first();

        $this->assertNotNull($timer);
        $this->assertSame('Chilli', $timer->name);
        $this->assertSame(25 * 60, $timer->seconds);
    }

    /* --------------------------- from the plan --------------------------- */

    #[Test]
    public function tonights_meal_can_be_cooked_straight_from_the_evening_face(): void
    {
        $recipe = $this->recipe(['steps' => ['Cook it.']]);

        // Travelled first: the meal has to land on the day the face will be
        // looking at, not on whatever day the suite happens to run.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', $this->household->displayTimezone()));

        Meal::create([
            'household_id' => $this->household->id,
            'on' => $this->household->todayLocal()->toDateString(),
            'slot' => 'dinner',
            'title' => 'Chilli',
            'recipe_id' => $recipe->id,
        ]);

        Livewire::test('display.evening')
            ->assertSee('Start cooking')
            ->call('cook')
            ->assertDispatched('cook', recipe: $recipe->id);
    }

    #[Test]
    public function a_meal_with_no_recipe_behind_it_does_not_offer_to_be_cooked(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', $this->household->displayTimezone()));

        Meal::create([
            'household_id' => $this->household->id,
            'on' => $this->household->todayLocal()->toDateString(),
            'slot' => 'dinner',
            'title' => 'Fish and chips',
        ]);

        Livewire::test('display.evening')
            ->assertSee('Fish and chips')
            ->assertDontSee('Start cooking');
    }
}
