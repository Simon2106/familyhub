<?php

namespace Tests\Feature\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\MealCollection;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use App\Services\Meals\MealFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The Ideas tab: the box, promoted. */
class IdeasTabTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);

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
            // Explicit: the factory tags everything, and a test of the tag
            // filter cannot start with every row already tagged.
            'tags' => [],
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

    /* ------------------------------ the page ----------------------------- */

    #[Test]
    public function meals_has_three_tabs(): void
    {
        Livewire::test('meals.page')
            ->assertSee('This week')
            ->assertSee('Ideas')
            ->assertSee('History')
            ->assertSet('tab', 'week');
    }

    #[Test]
    public function the_tab_stays_put_across_a_refresh(): void
    {
        // In the URL, so somebody sent a link to the ideas lands on the ideas.
        Livewire::withUrlParams(['tab' => 'ideas'])
            ->test('meals.page')
            ->assertSet('tab', 'ideas');
    }

    #[Test]
    public function a_made_up_tab_falls_back_to_the_week(): void
    {
        Livewire::withUrlParams(['tab' => 'nonsense'])
            ->test('meals.page')
            ->assertSet('tab', 'week');
    }

    /* ------------------------------- adding ------------------------------ */

    #[Test]
    public function an_idea_can_be_nothing_but_a_name(): void
    {
        // The first-class case: no model, no queue, nothing to wait for.
        Livewire::test('meals.ideas')
            ->set('mode', 'name')
            ->set('newTitle', 'Fajitas')
            ->call('add')
            ->assertSee('Fajitas');

        $idea = Recipe::firstWhere('title', 'Fajitas');

        $this->assertSame('ready', $idea->status);
        $this->assertTrue($idea->neverCooked());
    }

    #[Test]
    public function a_nameless_idea_is_refused_kindly(): void
    {
        Livewire::test('meals.ideas')
            ->set('mode', 'name')
            ->set('newTitle', '   ')
            ->call('add')
            ->assertSee('Give it a name');

        $this->assertSame(0, Recipe::count());
    }

    /* ------------------------------ opinions ----------------------------- */

    #[Test]
    public function a_free_text_idea_can_be_rated_without_ever_becoming_a_recipe(): void
    {
        // Nothing but a title — the factory's ingredients would make this a
        // test of a recipe, which is the opposite of the point.
        $idea = $this->idea('That chicken thing', ['ingredients' => null, 'steps' => null]);

        Livewire::test('meals.ideas')
            ->call('inspect', $idea->id)
            ->call('rate', $idea->id, 5)
            ->assertSee('5.0');

        $this->assertSame(5, RecipeRating::first()->stars);
        $this->assertNull($idea->fresh()->ingredients, 'Still just a name.');
    }

    #[Test]
    public function a_login_with_no_member_is_told_rather_than_ignored(): void
    {
        Member::query()->delete();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $idea = $this->idea('Fish pie');

        Livewire::test('meals.ideas')
            ->call('rate', $idea->id, 5)
            ->assertSee('Link this login to a family member');

        $this->assertSame(0, RecipeRating::count());
    }

    #[Test]
    public function tags_notes_and_favourites_are_all_one_tap(): void
    {
        $idea = $this->idea('Fish pie');

        Livewire::test('meals.ideas')
            ->call('inspect', $idea->id)
            ->call('toggleTag', $idea->id, 'fakeaway')
            ->call('toggleFavourite', $idea->id)
            ->set('noteDraft', "Joey won't eat the sauce")
            ->call('saveNote');

        $idea->refresh();

        $this->assertTrue($idea->hasTag('fakeaway'));
        $this->assertTrue($idea->is_favourite);
        $this->assertSame("Joey won't eat the sauce", $idea->notes);

        // And off again.
        Livewire::test('meals.ideas')->call('toggleTag', $idea->id, 'fakeaway');
        $this->assertFalse($idea->fresh()->hasTag('fakeaway'));
    }

    /* ------------------------------ filtering ---------------------------- */

    #[Test]
    public function the_filters_answer_the_questions_a_family_actually_asks(): void
    {
        $favourite = $this->idea('Fish pie', ['is_favourite' => true]);
        $ages = $this->idea('Chilli');
        $recent = $this->idea('Curry');
        $never = $this->idea('Something new');
        $quick = $this->idea('Beans on toast', ['tags' => ['quick']]);

        $this->ate($ages, '2026-07-01');
        $this->ate($recent, '2026-09-05');

        $titles = fn (string $filter) => Livewire::test('meals.ideas')
            ->set('filter', $filter)
            ->instance()->ideas->pluck('title')->all();

        $this->assertSame(['Fish pie'], $titles('favourites'));
        $this->assertContains('Chilli', $titles('while'));
        $this->assertNotContains('Curry', $titles('while'), 'Had four days ago.');
        $this->assertContains('Something new', $titles('never'));
        $this->assertNotContains('Chilli', $titles('never'));
        $this->assertSame(['Beans on toast'], $titles('quick'));
        $this->assertCount(5, $titles('all'));
    }

    #[Test]
    public function a_collection_is_a_pile_somebody_made(): void
    {
        $idea = $this->idea('Roast chicken');

        $component = Livewire::test('meals.ideas')
            ->set('collectionName', 'Sunday roasts')
            ->call('addCollection');

        $collection = MealCollection::firstWhere('name', 'Sunday roasts');

        $component->call('toggleCollection', $idea->id, $collection->id)
            ->set('filter', 'collection:'.$collection->id)
            ->assertSee('Roast chicken');

        $this->assertTrue($idea->fresh()->collections->contains($collection->id));
    }

    #[Test]
    public function sorting_puts_the_unrated_last_rather_than_bottom_of_a_scale(): void
    {
        // A nought is not an opinion.
        $good = $this->idea('Fish pie');
        $this->idea('Never rated');

        app(MealFeedback::class)->star($good, $this->simon, 4);

        $titles = Livewire::test('meals.ideas')
            ->set('order', 'rating')
            ->instance()->ideas->pluck('title')->all();

        $this->assertSame('Fish pie', $titles[0]);
    }

    /* ------------------------------ history ------------------------------ */

    #[Test]
    public function history_shows_what_was_eaten_by_week(): void
    {
        $idea = $this->idea('Fish pie');
        $this->ate($idea, '2026-09-01');
        $this->ate($idea, '2026-09-08');

        Livewire::test('meals.history')
            ->assertSee('Fish pie')
            ->assertSee('This week');
    }

    #[Test]
    public function tonight_is_not_history_yet(): void
    {
        $idea = $this->idea('Fish pie');
        $this->ate($idea, '2026-09-09');

        Livewire::test('meals.history')->assertSee('Nothing eaten yet');
    }
}
