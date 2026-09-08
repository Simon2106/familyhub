<?php

namespace Tests\Feature\Assistant;

use App\Models\BinCollection;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Checklist;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\SchoolDate;
use App\Services\Assistant\HouseholdFacts;
use App\Services\Chores\ChoreBoard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the assistant is able to find out, and — just as importantly — what it
 * is unable to do while finding it out.
 */
class HouseholdFactsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected Member $simon;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "this week" has days either side of today in it.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $this->simon = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Simon', 'is_child' => false,
        ]);
        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'member_id' => $this->simon->id,
            'name' => 'Simon',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function facts(): HouseholdFacts
    {
        return app(HouseholdFacts::class);
    }

    protected function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/London')->startOfDay();
    }

    /* ----------------------------- calendar ----------------------------- */

    #[Test]
    public function the_calendar_says_what_is_on_and_whose_it_is(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Dentist',
            'location' => 'High Street',
            // Stored in UTC, as everything is; September in London is UTC+1.
            'start_at' => '2026-09-10 08:30:00',
            'end_at' => '2026-09-10 09:00:00',
        ]);

        $text = $this->facts()->calendar($this->household, $this->day('2026-09-09'), $this->day('2026-09-12'));

        $this->assertStringContainsString('Dentist', $text);
        $this->assertStringContainsString('09:30', $text, 'Local time, not the stored UTC.');
        $this->assertStringContainsString('High Street', $text);
        // The citation the answer is expected to quote back.
        $this->assertStringContainsString('from the Simon calendar', $text);
    }

    #[Test]
    public function a_calendar_with_no_owner_is_cited_by_its_name(): void
    {
        // This household keeps one shared calendar called "Family"; a citation
        // built only from the owner would be blank on every event they have.
        $shared = Calendar::factory()->create([
            'calendar_account_id' => $this->calendar->calendar_account_id,
            'member_id' => null,
            'name' => 'Family',
        ]);

        Event::factory()->create([
            'calendar_id' => $shared->id, 'title' => 'Swimming',
            'start_at' => '2026-09-10 08:00:00', 'end_at' => '2026-09-10 09:00:00',
        ]);

        $this->assertStringContainsString(
            'from the Family calendar',
            $this->facts()->calendar($this->household, $this->day('2026-09-10'), $this->day('2026-09-10')),
        );
    }

    #[Test]
    public function the_calendar_can_be_narrowed_to_one_person(): void
    {
        $jenna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna']);
        $hers = Calendar::factory()->create([
            'calendar_account_id' => $this->calendar->calendar_account_id,
            'member_id' => $jenna->id,
        ]);

        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Simon thing',
            'start_at' => '2026-09-10 09:00:00', 'end_at' => '2026-09-10 10:00:00',
        ]);
        Event::factory()->create([
            'calendar_id' => $hers->id, 'title' => 'Jenna thing',
            'start_at' => '2026-09-10 11:00:00', 'end_at' => '2026-09-10 12:00:00',
        ]);

        $text = $this->facts()->calendar($this->household, $this->day('2026-09-10'), $this->day('2026-09-10'), 'jenna');

        $this->assertStringContainsString('Jenna thing', $text);
        $this->assertStringNotContainsString('Simon thing', $text);
    }

    #[Test]
    public function an_empty_calendar_says_so_rather_than_nothing(): void
    {
        // A blank tool result is the one thing most likely to be filled in
        // with something plausible.
        $text = $this->facts()->calendar($this->household, $this->day('2026-09-09'), $this->day('2026-09-12'));

        $this->assertStringContainsString('Nothing in the calendar', $text);
    }

    #[Test]
    public function an_event_on_a_hidden_calendar_is_not_reported(): void
    {
        $hidden = Calendar::factory()->hidden()->create([
            'calendar_account_id' => $this->calendar->calendar_account_id,
        ]);

        Event::factory()->create([
            'calendar_id' => $hidden->id, 'title' => 'Private thing',
            'start_at' => '2026-09-10 09:00:00', 'end_at' => '2026-09-10 10:00:00',
        ]);

        $this->assertStringNotContainsString(
            'Private thing',
            $this->facts()->calendar($this->household, $this->day('2026-09-09'), $this->day('2026-09-12')),
        );
    }

    /* ------------------------------ the rest ---------------------------- */

    #[Test]
    public function the_meal_plan_is_read_back_by_day_and_slot(): void
    {
        Meal::factory()->create([
            'household_id' => $this->household->id, 'on' => '2026-09-10',
            'slot' => 'dinner', 'title' => 'Fish pie',
        ]);

        $text = $this->facts()->meals($this->household, $this->day('2026-09-09'), $this->day('2026-09-13'));

        $this->assertStringContainsString('From the meal plan', $text);
        $this->assertStringContainsString('Fish pie', $text);
        $this->assertStringContainsString('dinner', $text);
    }

    #[Test]
    public function a_chore_says_whether_it_is_done_waiting_or_outstanding(): void
    {
        $chore = Chore::factory()->needingApproval()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
            'title' => 'Feed the cat',
        ]);

        app(ChoreBoard::class)->complete($chore, $this->day('2026-09-09'), $this->joey);

        $text = $this->facts()->chores($this->household, $this->day('2026-09-09'), $this->day('2026-09-09'));

        $this->assertStringContainsString('Feed the cat', $text);
        $this->assertStringContainsString('Joey', $text);
        $this->assertStringContainsString('waiting for a grown-up', $text);
    }

    #[Test]
    public function the_lists_are_read_with_their_due_dates(): void
    {
        $todo = Checklist::factory()->create([
            'household_id' => $this->household->id, 'name' => 'To do', 'type' => 'todo',
        ]);
        $todo->items()->create(['title' => 'Return the library books', 'due_on' => '2026-09-11']);

        $shopping = Checklist::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Shopping', 'type' => 'shopping',
        ]);
        $shopping->items()->create(['title' => 'Milk', 'quantity' => '2 pints']);

        $text = $this->facts()->lists($this->household);

        $this->assertStringContainsString('Return the library books', $text);
        $this->assertStringContainsString('Fri 11 Sep', $text);
        $this->assertStringContainsString('Milk', $text);
        $this->assertStringContainsString('2 pints', $text);
    }

    #[Test]
    public function a_done_item_is_left_out_unless_it_is_asked_for(): void
    {
        $todo = Checklist::factory()->create([
            'household_id' => $this->household->id, 'name' => 'To do', 'type' => 'todo',
        ]);
        $todo->items()->create(['title' => 'Post the form', 'is_done' => true]);

        $this->assertStringNotContainsString('Post the form', $this->facts()->lists($this->household, 'todo'));
        $this->assertStringContainsString('Post the form', $this->facts()->lists($this->household, 'todo', includeDone: true));
    }

    #[Test]
    public function the_next_bins_are_grouped_onto_their_day(): void
    {
        BinCollection::factory()->create(['household_id' => $this->household->id, 'on' => '2026-09-15', 'kind' => 'recycling']);
        BinCollection::factory()->create(['household_id' => $this->household->id, 'on' => '2026-09-15', 'kind' => 'food']);
        BinCollection::factory()->create(['household_id' => $this->household->id, 'on' => '2026-09-08', 'kind' => 'refuse']);

        $text = $this->facts()->bins($this->household);

        $this->assertStringContainsString('Tue 15 Sep', $text);
        $this->assertStringContainsString('Recycling', $text);
        $this->assertStringContainsString('Food', $text);
        // Yesterday's collection is not news.
        $this->assertStringNotContainsString('Rubbish', $text);
    }

    #[Test]
    public function school_closures_are_read_with_the_schools_code(): void
    {
        $school = Place::factory()->school()->create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate', 'short_code' => 'SG',
        ]);

        SchoolDate::factory()->create([
            'place_id' => $school->id, 'kind' => 'inset',
            'name' => 'INSET day', 'starts_on' => '2026-09-11', 'ends_on' => '2026-09-11',
        ]);

        $text = $this->facts()->schoolDates($this->household, $this->day('2026-09-09'), $this->day('2026-09-30'));

        $this->assertStringContainsString('SG', $text);
        $this->assertStringContainsString('Fri 11 Sep', $text);
    }

    #[Test]
    public function points_are_read_per_child_with_anything_pending(): void
    {
        $chore = Chore::factory()->needingApproval()->create([
            'household_id' => $this->household->id, 'member_id' => $this->joey->id, 'points' => 3,
        ]);

        app(ChoreBoard::class)->complete($chore, $this->day('2026-09-09'), $this->joey);

        $text = $this->facts()->points($this->household);

        $this->assertStringContainsString('Joey', $text);
        // Not yet approved, so not yet earned — the "0 points saved" confusion
        // in words rather than in a number.
        $this->assertStringContainsString('0 points', $text);
        $this->assertStringContainsString('3 waiting', $text);
        $this->assertStringNotContainsString('Simon', $text, 'Grown-ups do not have a points balance.');
    }

    #[Test]
    public function a_recipe_comes_back_with_its_ingredients(): void
    {
        Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Fish pie',
            'ingredients' => [
                ['item' => 'smoked haddock', 'quantity' => 400, 'unit' => 'g'],
                ['item' => 'potatoes', 'quantity' => 1, 'unit' => 'kg'],
            ],
            'steps' => ['Boil the potatoes.'],
        ]);

        $text = $this->facts()->recipe($this->household, 'fish');

        $this->assertStringContainsString('From the recipe box', $text);
        $this->assertStringContainsString('smoked haddock', $text);
        $this->assertStringContainsString('Boil the potatoes', $text);
    }

    #[Test]
    public function search_is_the_fallback_when_the_date_is_the_unknown(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Sports day',
            'start_at' => '2027-06-18 09:00:00', 'end_at' => '2027-06-18 15:00:00',
        ]);

        $text = $this->facts()->search($this->household, 'sports day');

        $this->assertStringContainsString('Sports day', $text);
        $this->assertStringContainsString('Fri 18 Jun 2027', $text);
    }

    /* ------------------------------- dates ------------------------------ */

    #[Test]
    public function something_that_has_already_happened_is_marked_as_such(): void
    {
        // Asked when Joey next has kickboxing, the assistant answered with a
        // trip three months gone and worded it as a plan — because a bare
        // date in a line of text reads as an upcoming one.
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Kickboxing in Belgium',
            'start_at' => '2026-06-19 09:00:00', 'end_at' => '2026-06-19 17:00:00',
        ]);

        $text = $this->facts()->search($this->household, 'kickboxing');

        $this->assertStringContainsString('Fri 19 Jun 2026 (in the past)', $text);
    }

    #[Test]
    public function today_is_marked_too_so_it_is_never_read_as_tomorrow(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Swimming',
            'start_at' => '2026-09-09 08:00:00', 'end_at' => '2026-09-09 09:00:00',
        ]);

        $this->assertStringContainsString(
            'Wed 9 Sep 2026 (today)',
            $this->facts()->calendar($this->household, $this->day('2026-09-09'), $this->day('2026-09-09')),
        );
    }

    #[Test]
    public function something_still_to_come_is_left_unmarked(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Sports day',
            'start_at' => '2026-09-20 09:00:00', 'end_at' => '2026-09-20 15:00:00',
        ]);

        $text = $this->facts()->calendar($this->household, $this->day('2026-09-20'), $this->day('2026-09-20'));

        $this->assertStringContainsString('Sun 20 Sep 2026', $text);
        $this->assertStringNotContainsString('(in the past)', $text);
        $this->assertStringNotContainsString('(today)', $text);
    }

    #[Test]
    public function search_admits_it_has_no_floor(): void
    {
        // It reaches back over everything the household has ever had, so the
        // model has to be told to check the dates rather than trust the order.
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Sports day',
            'start_at' => '2027-06-18 09:00:00', 'end_at' => '2027-06-18 15:00:00',
        ]);

        $this->assertStringContainsString(
            'past and future alike',
            $this->facts()->search($this->household, 'sports day'),
        );
    }

    /* ------------------------------ safety ------------------------------ */

    #[Test]
    public function a_date_the_model_made_up_falls_back_to_today(): void
    {
        // A model that answers "next Tuesday" instead of a date should give a
        // useless answer, not a 500 on somebody's phone.
        $today = $this->household->todayLocal();

        $this->assertTrue($this->facts()->date($this->household, 'next tuesday')->isSameDay($today));
        $this->assertTrue($this->facts()->date($this->household, null)->isSameDay($today));
        $this->assertTrue($this->facts()->date($this->household, ['nonsense'])->isSameDay($today));
        $this->assertSame('2026-09-15', $this->facts()->date($this->household, '2026-09-15')->toDateString());
    }

    #[Test]
    public function a_range_longer_than_half_a_year_is_cut_short(): void
    {
        $from = $this->day('2026-09-09');

        $this->assertSame(
            $from->addDays(HouseholdFacts::MAX_DAYS)->toDateString(),
            $this->facts()->clamp($from, $from->addYears(5))->toDateString(),
        );

        // Backwards is a mistake too, and answering for one day beats throwing.
        $this->assertSame($from->toDateString(), $this->facts()->clamp($from, $from->subMonth())->toDateString());
    }

    #[Test]
    public function nothing_the_assistant_can_reach_writes_anything(): void
    {
        // The promise made on the page, tested rather than asserted: every
        // reader is run, and the database must be byte-for-byte where it was.
        Event::factory()->create([
            'calendar_id' => $this->calendar->id, 'title' => 'Dentist',
            'start_at' => '2026-09-10 09:30:00', 'end_at' => '2026-09-10 10:00:00',
        ]);
        Meal::factory()->create(['household_id' => $this->household->id, 'on' => '2026-09-10', 'title' => 'Fish pie']);
        Chore::factory()->create(['household_id' => $this->household->id, 'member_id' => $this->joey->id]);
        BinCollection::factory()->create(['household_id' => $this->household->id, 'on' => '2026-09-15']);
        Recipe::factory()->create(['household_id' => $this->household->id, 'title' => 'Fish pie']);
        Checklist::factory()->create(['household_id' => $this->household->id, 'type' => 'todo'])
            ->items()->create(['title' => 'Library books']);

        $before = $this->snapshot();

        $from = $this->day('2026-09-09');
        $to = $this->day('2026-09-30');

        $this->facts()->calendar($this->household, $from, $to);
        $this->facts()->calendar($this->household, $from, $to, 'joey');
        $this->facts()->meals($this->household, $from, $to);
        $this->facts()->chores($this->household, $from, $to);
        $this->facts()->chores($this->household, $from, $to, 'joey');
        $this->facts()->lists($this->household);
        $this->facts()->lists($this->household, 'shopping', includeDone: true);
        $this->facts()->bins($this->household);
        $this->facts()->schoolDates($this->household, $from, $to);
        $this->facts()->points($this->household);
        $this->facts()->recipe($this->household, 'fish');
        $this->facts()->search($this->household, 'dentist');

        $this->assertSame($before, $this->snapshot());
    }

    /** Every row of every table, as it stands. */
    protected function snapshot(): array
    {
        $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))
            ->pluck('name')
            ->reject(fn (string $t) => str_starts_with($t, 'sqlite_'))
            ->sort()
            ->values();

        return $tables
            ->mapWithKeys(fn (string $table) => [
                $table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all(),
            ])
            ->all();
    }
}
