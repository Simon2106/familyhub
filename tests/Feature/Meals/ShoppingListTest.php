<?php

namespace Tests\Feature\Meals;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Meals\ShoppingListGenerator;
use App\Services\Meals\ShoppingListResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merging a week of planned meals into something to take to a supermarket.
 */
class ShoppingListTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @param list<array{0: float|null, 1: ?string, 2: string}> $ingredients */
    protected function plan(string $on, string $title, array $ingredients = []): Meal
    {
        $recipe = $ingredients === [] ? null : Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => $title,
            'ingredients' => array_map(
                fn (array $row) => ['quantity' => $row[0], 'unit' => $row[1], 'item' => $row[2], 'note' => null],
                $ingredients,
            ),
        ]);

        return Meal::create([
            'household_id' => $this->household->id,
            'on' => $on,
            'slot' => 'dinner',
            'title' => $title,
            'recipe_id' => $recipe?->id,
        ]);
    }

    protected function generate(): ShoppingListResult
    {
        return app(ShoppingListGenerator::class)->generate($this->household, '2026-09-07', '2026-09-13');
    }

    protected function shoppingList(): Checklist
    {
        return ShoppingListGenerator::listFor($this->household);
    }

    #[Test]
    public function ingredients_from_planned_recipes_land_on_the_list(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[4.0, null, 'chicken thigh'], [200.0, 'g', 'chorizo']]);

        $result = $this->generate();

        $this->assertSame(2, $result->added);
        $this->assertDatabaseHas('checklist_items', ['title' => 'Chicken thigh', 'quantity' => '4']);
        $this->assertDatabaseHas('checklist_items', ['title' => 'Chorizo', 'quantity' => '200 g']);
    }

    #[Test]
    public function the_same_ingredient_across_two_meals_becomes_one_line(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);
        $this->plan('2026-09-10', 'Curry', [[2.0, null, 'onion']]);

        $this->generate();

        $this->assertSame(1, ChecklistItem::where('title', 'Onion')->count());
        $this->assertDatabaseHas('checklist_items', ['title' => 'Onion', 'quantity' => '3']);
    }

    #[Test]
    public function different_units_of_the_same_thing_stay_apart(): void
    {
        // 200g of chorizo and two tins of it are not four hundred of anything.
        $this->plan('2026-09-09', 'Traybake', [[200.0, 'g', 'chorizo']]);
        $this->plan('2026-09-10', 'Stew', [[2.0, 'tin', 'chorizo']]);

        $this->generate();

        $this->assertSame(2, ChecklistItem::where('title', 'Chorizo')->count());
        $this->assertDatabaseHas('checklist_items', ['quantity' => '200 g']);
        $this->assertDatabaseHas('checklist_items', ['quantity' => '2 tin']);
    }

    #[Test]
    public function an_unmeasured_ingredient_still_gets_a_line(): void
    {
        $this->plan('2026-09-09', 'Salad', [[null, null, 'olive oil'], [null, 'handful', 'basil']]);

        $this->generate();

        $this->assertDatabaseHas('checklist_items', ['title' => 'Olive oil', 'quantity' => null]);
        $this->assertDatabaseHas('checklist_items', ['title' => 'Basil', 'quantity' => 'handful']);
    }

    #[Test]
    public function free_text_meals_are_skipped_and_counted(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);
        $this->plan('2026-09-10', 'Leftovers');
        $this->plan('2026-09-11', 'Out');

        $result = $this->generate();

        $this->assertSame(1, $result->added);
        $this->assertSame(2, $result->freeText);
        $this->assertStringContainsString('2 meals have no recipe', $result->sentence());
    }

    #[Test]
    public function a_week_of_free_text_says_so_rather_than_looking_broken(): void
    {
        $this->plan('2026-09-09', 'Leftovers');

        $result = $this->generate();

        $this->assertSame(0, $result->added);
        $this->assertStringContainsString('None of this week', $result->sentence());
        $this->assertStringContainsString('nothing to add', $result->sentence());
    }

    #[Test]
    public function running_it_twice_does_not_duplicate_anything(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);

        $this->generate();
        $second = $this->generate();

        $this->assertSame(0, $second->added);
        $this->assertSame(1, $second->alreadyThere);
        $this->assertSame(1, ChecklistItem::count());
    }

    #[Test]
    public function something_already_written_on_the_list_by_hand_is_left_alone(): void
    {
        ChecklistItem::create([
            'checklist_id' => $this->shoppingList()->id,
            'title' => 'Onions',
            'quantity' => 'a bag',
        ]);

        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);

        $this->generate();

        // "Onions" and "onion" are the same errand; the hand-written one wins.
        $this->assertSame(1, ChecklistItem::count());
        $this->assertDatabaseHas('checklist_items', ['title' => 'Onions', 'quantity' => 'a bag']);
    }

    #[Test]
    public function something_already_bought_does_not_come_back(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);

        $this->generate();

        $this->shoppingList()->items()->first()->toggle();

        $again = $this->generate();

        $this->assertSame(0, $again->added);
        $this->assertSame(1, ChecklistItem::count());
    }

    #[Test]
    public function meals_outside_the_week_are_not_shopped_for(): void
    {
        $this->plan('2026-09-20', 'Next week', [[1.0, null, 'onion']]);

        $this->assertSame(0, $this->generate()->added);
    }

    #[Test]
    public function the_button_on_the_meal_plan_generates_and_says_what_it_did(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion'], [200.0, 'g', 'chorizo']]);
        $this->plan('2026-09-10', 'Leftovers');

        Livewire::test('meals.plan')
            ->call('generateShoppingList')
            ->assertDispatched('saved', message: 'Added 2 items · 1 meal has no recipe.')
            ->assertDispatched('todos-changed');

        $this->assertSame(2, ChecklistItem::count());
    }

    #[Test]
    public function the_supermarket_view_shows_the_shopping_list_and_nothing_else(): void
    {
        Checklist::home($this->household)->items()->create(['title' => 'Book the dentist']);

        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);
        $this->generate();

        $this->get(route('shopping'))
            ->assertOk()
            ->assertSee('Onion')
            ->assertDontSee('Book the dentist');
    }

    #[Test]
    public function the_supermarket_view_ticks_items_off(): void
    {
        $this->plan('2026-09-09', 'Traybake', [[1.0, null, 'onion']]);
        $this->generate();

        $item = ChecklistItem::first();

        Livewire::test('display.lists', ['only' => 'shopping'])->call('toggle', $item->id);

        $this->assertTrue($item->fresh()->is_done);
    }
}
