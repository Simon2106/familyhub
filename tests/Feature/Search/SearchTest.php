<?php

namespace Tests\Feature\Search;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\Checklist;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\Reward;
use App\Models\Routine;
use App\Models\User;
use App\Services\Meals\ShoppingListGenerator;
use App\Services\Search\HouseholdSearch;
use App\Services\Search\SearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** One box that looks everywhere. */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function search(string $q)
    {
        return app(HouseholdSearch::class)->search($q, $this->household);
    }

    protected function event(string $title, string $at, array $attributes = []): Event
    {
        return Event::factory()->create($attributes + [
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => $at,
            'end_at' => CarbonImmutable::parse($at)->addHour(),
        ]);
    }

    /* ------------------------------ parsing ------------------------------ */

    #[Test]
    public function a_month_narrows_the_period_without_being_a_word_to_match(): void
    {
        $query = SearchQuery::parse('dentist march', CarbonImmutable::parse('2026-09-09'));

        $this->assertSame(['dentist'], $query->terms);
        $this->assertSame('2027-03-01', $query->from->toDateString(), 'The coming March, not the one gone.');
        $this->assertSame('2027-03-31', $query->to->toDateString());
    }

    #[Test]
    public function a_month_still_to_come_this_year_stays_this_year(): void
    {
        $query = SearchQuery::parse('dentist december', CarbonImmutable::parse('2026-09-09'));

        $this->assertSame('2026-12-01', $query->from->toDateString());
    }

    #[Test]
    public function relative_periods_are_understood(): void
    {
        $today = CarbonImmutable::parse('2026-09-09');

        $this->assertSame('2026-09-09', SearchQuery::parse('today', $today)->from->toDateString());
        $this->assertSame('2026-09-10', SearchQuery::parse('tomorrow', $today)->from->toDateString());
        $this->assertSame('2026-09-07', SearchQuery::parse('next week', $today)->from->subWeek()->toDateString());
        $this->assertSame('2026', SearchQuery::parse('party 2026', $today)->period);
    }

    #[Test]
    public function very_short_words_are_not_searched_for(): void
    {
        // Otherwise "a" matches everything and nothing is ranked.
        $this->assertSame(['dentist'], SearchQuery::parse('a dentist', CarbonImmutable::now())->terms);
    }

    /* ------------------------------ finding ------------------------------ */

    #[Test]
    public function it_finds_an_event_by_title(): void
    {
        $this->event('Dentist appointment', '2026-09-15 09:00:00');

        $results = $this->search('dentist');

        $this->assertCount(1, $results);
        $this->assertSame('event', $results[0]->type);
        $this->assertSame('Dentist appointment', $results[0]->title);
        $this->assertSame('2026-09-15', $results[0]->date->toDateString());
    }

    #[Test]
    public function a_month_narrows_which_dentist_is_meant(): void
    {
        $this->event('Dentist appointment', '2026-09-15 09:00:00');
        $this->event('Dentist appointment', '2027-03-04 09:00:00');

        $all = $this->search('dentist');
        $march = $this->search('dentist march');

        $this->assertCount(2, $all);
        $this->assertCount(1, $march);
        $this->assertSame('2027-03-04', $march[0]->date->toDateString());
    }

    #[Test]
    public function an_event_is_found_by_where_it_is_and_who_it_is_for(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Sienna']);
        $event = $this->event('Hospital appointment', '2026-09-15 09:00:00', ['location' => 'Stoke Mandeville']);
        $event->members()->attach($sienna->id);

        $this->assertCount(1, $this->search('mandeville'));

        // Two: the event she is on, and Sienna herself.
        $sienna = $this->search('sienna');
        $this->assertCount(2, $sienna);
        $this->assertEqualsCanonicalizing(['event', 'member'], $sienna->pluck('type')->all());
    }

    #[Test]
    public function it_finds_to_dos_and_shopping_apart_from_each_other(): void
    {
        Checklist::home($this->household)->items()->create(['title' => 'Book the dentist']);
        ShoppingListGenerator::listFor($this->household)
            ->items()->create(['title' => 'Toothpaste']);

        $this->assertSame('todo', $this->search('book the dentist')->first()->type);
        $this->assertSame('shopping', $this->search('toothpaste')->first()->type);
    }

    #[Test]
    public function it_finds_chores_routines_and_rewards(): void
    {
        $joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true]);

        Chore::factory()->create(['household_id' => $this->household->id, 'member_id' => $joey->id, 'title' => 'Feed the cat']);

        $routine = Routine::factory()->create([
            'household_id' => $this->household->id, 'member_id' => $joey->id, 'name' => 'Morning',
        ]);
        $routine->steps()->create(['title' => 'Brush teeth']);

        Reward::factory()->create(['household_id' => $this->household->id, 'name' => 'Screen time']);

        $this->assertSame('chore', $this->search('feed the cat')->first()->type);
        $this->assertSame('routine', $this->search('brush teeth')->first()->type, 'Found by its steps.');
        $this->assertSame('reward', $this->search('screen time')->first()->type);
    }

    #[Test]
    public function it_finds_a_recipe_by_its_ingredients(): void
    {
        Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Traybake',
            'ingredients' => [['quantity' => 200, 'unit' => 'g', 'item' => 'chorizo', 'note' => null]],
            'tags' => ['quick'],
        ]);

        $this->assertSame('recipe', $this->search('chorizo')->first()->type);
        $this->assertSame('recipe', $this->search('quick')->first()->type, 'And by its tags.');
    }

    #[Test]
    public function it_finds_a_meal_in_the_plan(): void
    {
        Meal::create([
            'household_id' => $this->household->id, 'on' => '2026-09-11', 'slot' => 'dinner', 'title' => 'Fish and chips',
        ]);

        $result = $this->search('fish')->first();

        $this->assertSame('meal', $result->type);
        $this->assertSame('2026-09-11', $result->date->toDateString());
    }

    #[Test]
    public function it_searches_the_text_a_captured_item_was_read_from(): void
    {
        $capture = Capture::factory()->reviewing()->create([
            'household_id' => $this->household->id,
            'subject' => 'FW: Flu vaccination',
            'body_text' => 'The immunisation team will visit on the 25th.',
        ]);

        CaptureItem::factory()->create(['capture_id' => $capture->id, 'title' => 'Flu vaccination']);

        $this->assertCount(1, $this->search('immunisation'), 'Found through the source text.');
    }

    #[Test]
    public function it_finds_places_by_their_aliases(): void
    {
        $place = Place::create([
            'household_id' => $this->household->id, 'name' => 'Holy Trinity School', 'type' => 'school',
        ]);
        $place->aliases()->create(['alias' => 'HT', 'kind' => 'name']);

        $this->assertSame('place', $this->search('holy trinity')->first()->type);
    }

    /* ------------------------------ ranking ------------------------------ */

    #[Test]
    public function a_title_match_beats_a_match_buried_in_the_notes(): void
    {
        $this->event('Swimming lesson', '2026-09-15 09:00:00');
        $this->event('Parents evening', '2026-09-16 09:00:00', ['notes' => 'Ask about swimming']);

        $results = $this->search('swimming');

        $this->assertSame('Swimming lesson', $results[0]->title);
    }

    #[Test]
    public function results_carry_a_group_and_a_way_in(): void
    {
        $this->event('Dentist', '2026-09-15 09:00:00');

        $result = $this->search('dentist')->first();

        $this->assertSame('Calendar', $result->group());
        $this->assertStringContainsString('/app', $result->url);
        $this->assertSame(['edit-event' => 1], $result->opens);
    }

    #[Test]
    public function nothing_typed_finds_nothing(): void
    {
        $this->event('Dentist', '2026-09-15 09:00:00');

        $this->assertCount(0, $this->search(''));
        $this->assertCount(0, $this->search('   '));
    }

    #[Test]
    public function a_wildcard_is_searched_for_rather_than_matching_everything(): void
    {
        $this->event('50% off day', '2026-09-15 09:00:00');
        $this->event('Ordinary day', '2026-09-16 09:00:00');

        $this->assertCount(1, $this->search('50%'));
    }

    #[Test]
    public function a_bare_period_lists_what_is_in_it(): void
    {
        $this->event('Dentist', '2026-09-15 09:00:00');
        $this->event('Long ago', '2025-01-05 09:00:00');

        $results = $this->search('september 2026');

        $this->assertGreaterThanOrEqual(1, $results->count());
        $this->assertTrue($results->contains(fn ($r) => $r->title === 'Dentist'));
        $this->assertFalse($results->contains(fn ($r) => $r->title === 'Long ago'));
    }

    #[Test]
    public function another_households_data_is_never_returned(): void
    {
        $other = Household::factory()->create();
        $account = CalendarAccount::factory()->create(['household_id' => $other->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);

        Event::factory()->create([
            'calendar_id' => $calendar->id, 'title' => 'Dentist', 'start_at' => '2026-09-15 09:00:00',
            'end_at' => '2026-09-15 10:00:00',
        ]);

        $this->assertCount(0, $this->search('dentist'));
    }

    #[Test]
    public function searching_costs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 40) as $n) {
            $this->event("Dentist {$n}", '2026-09-15 09:00:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->search('dentist');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One per source plus eager loads — not one per row.
        $this->assertLessThan(20, $queries, "Searching took {$queries} queries.");
    }

    /* -------------------------------- the box ------------------------------ */

    #[Test]
    public function the_box_waits_for_more_than_one_letter(): void
    {
        $this->event('Dentist', '2026-09-15 09:00:00');

        Livewire::test('search.box')
            ->set('q', 'd')
            ->assertDontSee('Dentist')
            ->set('q', 'dentist')
            ->assertSee('Dentist');
    }

    #[Test]
    public function results_are_grouped_under_headings(): void
    {
        $this->event('Swim club', '2026-09-15 09:00:00');
        Checklist::home($this->household)->items()->create(['title' => 'Swim kit']);

        Livewire::test('search.box')
            ->set('q', 'swim')
            ->assertSee('Calendar')
            ->assertSee('To-dos');
    }

    #[Test]
    public function it_says_when_a_period_narrowed_the_answer(): void
    {
        $this->event('Dentist', '2027-03-04 09:00:00');

        Livewire::test('search.box')
            ->set('q', 'dentist march')
            ->assertSee('in March 2027');
    }

    #[Test]
    public function nothing_found_says_so_rather_than_showing_an_empty_box(): void
    {
        Livewire::test('search.box')
            ->set('q', 'kumquat')
            ->assertSee('Nothing matches');
    }

    #[Test]
    public function a_result_can_be_opened_in_place_where_the_thing_is_on_the_page(): void
    {
        $event = $this->event('Dentist', '2026-09-15 09:00:00');

        Livewire::test('search.box', ['canOpen' => true])
            ->set('q', 'dentist')
            ->call('open', 'edit-event', $event->id)
            ->assertDispatched('edit-event')
            ->assertSet('q', '');
    }

    #[Test]
    public function a_deep_link_opens_the_thing_it_names(): void
    {
        $recipe = Recipe::factory()->create(['household_id' => $this->household->id, 'title' => 'Traybake']);

        $this->get(route('recipes', ['recipe' => $recipe->id]))
            ->assertOk()
            ->assertSee('Traybake');
    }
}
