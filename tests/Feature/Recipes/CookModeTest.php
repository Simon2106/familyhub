<?php

namespace Tests\Feature\Recipes;

use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Cooking from the wall, one step to a screen. */
class CookModeTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function recipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'title' => 'Fish pie',
            'status' => 'ready',
            'ingredients' => [
                ['item' => 'smoked haddock', 'quantity' => 400, 'unit' => 'g'],
                ['item' => 'potatoes', 'quantity' => 1, 'unit' => 'kg'],
            ],
            'steps' => ['Boil the potatoes for 20 minutes.', 'Poach the fish.', 'Bake until golden.'],
        ]);
    }

    #[Test]
    public function it_opens_on_the_ingredients_and_walks_through_the_steps(): void
    {
        // Gathering happens once, at the start; repeating the list beside step
        // four is how a wall runs out of room for the step.
        $recipe = $this->recipe();

        $component = Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertSee('What you need')
            ->assertSee('smoked haddock');

        $component->call('next')->assertSee('Boil the potatoes');
        $component->call('next')->assertSee('Poach the fish');
        $component->call('back')->assertSee('Boil the potatoes');
    }

    #[Test]
    public function a_recipe_with_no_ingredients_starts_at_the_first_step(): void
    {
        $recipe = $this->recipe(['ingredients' => null]);

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->assertSee('Boil the potatoes')
            ->assertDontSee('What you need');
    }

    #[Test]
    public function it_cannot_be_walked_off_either_end(): void
    {
        $recipe = $this->recipe();

        $component = Livewire::test('recipes.cook')->call('start', $recipe->id);

        $component->call('back')->assertSet('step', 0);

        foreach (range(1, 10) as $ignored) {
            $component->call('next');
        }

        // Four screens: the ingredients and three steps.
        $component->assertSet('step', 3);
    }

    #[Test]
    public function ingredients_are_ticked_off_and_untucked_again(): void
    {
        $recipe = $this->recipe();

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->call('gather', 0)
            ->assertSet('gathered', [0])
            ->call('gather', 1)
            ->assertSet('gathered', [0, 1])
            ->call('gather', 0)
            ->assertSet('gathered', [1]);
    }

    #[Test]
    public function the_ticks_are_about_this_evening_and_not_about_the_recipe(): void
    {
        // A chilli that remembered which onions were chopped in March would be
        // worse than one that remembered nothing.
        $recipe = $this->recipe();

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->call('gather', 0)
            ->call('close')
            ->call('start', $recipe->id)
            ->assertSet('gathered', []);
    }

    #[Test]
    public function finishing_closes_it_and_returns_to_the_recipe(): void
    {
        $recipe = $this->recipe();

        Livewire::test('recipes.cook')
            ->call('start', $recipe->id)
            ->call('next')->call('next')->call('next')
            ->assertSet('isLast', true)
            ->call('close')
            ->assertSet('recipeId', null)
            ->assertDontSee('Fish pie');
    }

    /* ------------------------------- timers ------------------------------ */

    #[Test]
    public function the_minutes_a_step_mentions_become_a_timer_button(): void
    {
        // Nobody who has just read "simmer for 20 minutes" wants to then type
        // 20 with a wooden spoon in their hand.
        $cook = Livewire::test('recipes.cook')->instance();

        $this->assertSame([20], $cook->minutesIn('Boil the potatoes for 20 minutes.'));
        $this->assertSame([5], $cook->minutesIn('Rest for 5 mins.'));
        $this->assertSame([3, 40], $cook->minutesIn('Fry 3 minutes, then bake 40 minutes.'));
    }

    #[Test]
    public function a_range_offers_the_longer_end(): void
    {
        // A timer that goes off early is one somebody has to set again.
        $cook = Livewire::test('recipes.cook')->instance();

        $this->assertSame([25], $cook->minutesIn('Simmer for 20-25 minutes.'));
        $this->assertSame([45], $cook->minutesIn('Bake 40 to 45 minutes.'));
    }

    #[Test]
    public function nonsense_is_not_offered_as_a_timer(): void
    {
        $cook = Livewire::test('recipes.cook')->instance();

        $this->assertSame([], $cook->minutesIn('Serves 4. Preheat to 200C.'));
        $this->assertSame([], $cook->minutesIn('Leave for 600 minutes.'), 'Ten hours is not a kitchen timer.');
    }

    /* ------------------------------- reach ------------------------------- */

    #[Test]
    public function the_recipe_box_offers_it_only_where_there_are_steps(): void
    {
        $withSteps = $this->recipe();
        $without = $this->recipe(['title' => 'That chicken thing', 'steps' => null]);

        Livewire::test('recipes.box')
            ->call('open', $withSteps->id)
            ->assertSee('Cook this');

        Livewire::test('recipes.box')
            ->call('open', $without->id)
            ->assertDontSee('Cook this');
    }

    #[Test]
    public function somebody_elses_recipe_cannot_be_cooked(): void
    {
        $theirs = Recipe::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'title' => 'Not ours',
            'steps' => ['Do a thing'],
        ]);

        Livewire::test('recipes.cook')
            ->call('start', $theirs->id)
            ->assertDontSee('Not ours');
    }

    /**
     * Ingredient rows arrive from an AI extraction, a shared page, or somebody
     * typing, so a row is often just an item. Everything that draws them —
     * the dialog, cook mode, the shopping list — reads four keys, and a row
     * missing one used to take the whole recipe dialog down with it.
     */
    #[Test]
    public function every_ingredient_row_comes_back_with_the_whole_shape(): void
    {
        $recipe = $this->recipe([
            'ingredients' => [
                ['item' => 'Olive oil'],
                ['item' => 'Sausages', 'quantity' => '8', 'note' => 'good ones'],
                ['item' => 'Tomatoes', 'quantity' => 400, 'unit' => 'g'],
                ['item' => '', 'quantity' => 3],
                'not an array',
            ],
        ]);

        $lines = $recipe->ingredientList();

        $this->assertCount(3, $lines, 'Rows with no item are dropped.');

        foreach ($lines as $line) {
            $this->assertSame(['quantity', 'unit', 'item', 'note'], array_keys($line));
        }

        $this->assertSame(
            ['quantity' => null, 'unit' => null, 'item' => 'Olive oil', 'note' => null],
            $lines[0],
        );
        $this->assertSame(8.0, $lines[1]['quantity']);
        $this->assertSame('good ones', $lines[1]['note']);
    }

    /** The dialog draws a bare row without falling over. */
    #[Test]
    public function the_recipe_dialog_draws_an_ingredient_with_nothing_but_a_name(): void
    {
        $recipe = $this->recipe(['ingredients' => [['item' => 'Olive oil']]]);

        Livewire::test('recipes.box')
            ->call('open', $recipe->id)
            ->assertOk()
            ->assertSee('Olive oil');
    }
}
