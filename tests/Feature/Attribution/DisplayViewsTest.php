<?php

namespace Tests\Feature\Attribution;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Services\Attribution\EventAttributor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wall's week view, day view and per-member columns.
 */
class DisplayViewsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected Member $simon;

    protected Member $jenna;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "this week" has days either side of today.
        CarbonImmutable::setTestNow('2026-07-08 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => null]);

        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon', 'colour' => '#2563eb']);
        $this->simon->aliases()->create(['alias' => 'SW']);

        $this->jenna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna', 'colour' => '#db2777']);
        $this->jenna->aliases()->create(['alias' => 'JW']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function event(string $title, string $when): Event
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => CarbonImmutable::parse($when, 'Europe/London'),
            'end_at' => CarbonImmutable::parse($when, 'Europe/London')->addHour(),
        ]);

        app(EventAttributor::class)->forget();
        app(EventAttributor::class)->apply($event);

        return $event->fresh();
    }

    #[Test]
    public function the_week_runs_monday_to_sunday_around_today(): void
    {
        $week = Livewire::test('display.wall')->instance()->week();

        $this->assertCount(7, $week);
        // Today is Wednesday 8 July 2026; the week starts on Monday the 6th.
        $this->assertSame('2026-07-06', $week[0]['date']);
        $this->assertSame('2026-07-12', $week[6]['date']);
        $this->assertTrue($week[2]['is_today']);
    }

    #[Test]
    public function the_week_view_is_the_default(): void
    {
        $this->event('SW dentist', '2026-07-09 09:00');

        Livewire::test('display.wall')
            ->assertSee('This week')
            // view starts as 'week' in the Alpine state.
            ->assertSee("view: 'week'", escape: false);
    }

    #[Test]
    public function the_week_view_lists_only_days_that_have_something_on(): void
    {
        $this->event('SW dentist', '2026-07-09 09:00');

        $withEvents = Livewire::test('display.wall')->instance()->weekWithEvents();

        $this->assertCount(1, $withEvents);
        $this->assertSame('2026-07-09', $withEvents[0]['date']);
    }

    #[Test]
    public function an_event_naming_two_people_appears_in_both_columns(): void
    {
        $this->event('SW + JW dentist', '2026-07-09 09:00');

        $days = collect(Livewire::test('display.wall')->instance()->days());
        $thursday = $days->firstWhere('date', '2026-07-09');

        $this->assertTrue($thursday['events_by_member']->has($this->simon->id));
        $this->assertTrue($thursday['events_by_member']->has($this->jenna->id));
        $this->assertSame('SW + JW dentist', $thursday['events_by_member'][$this->simon->id]->first()->title);
        $this->assertSame('SW + JW dentist', $thursday['events_by_member'][$this->jenna->id]->first()->title);
    }

    #[Test]
    public function an_event_belonging_to_nobody_goes_in_the_household_bucket(): void
    {
        $this->event('Bin day', '2026-07-09 07:00');

        $days = collect(Livewire::test('display.wall')->instance()->days());
        $thursday = $days->firstWhere('date', '2026-07-09');

        $this->assertTrue($thursday['events_by_member']->has('household'));
        $this->assertTrue(Livewire::test('display.wall')->instance()->hasHouseholdEvents());
    }

    #[Test]
    public function the_household_column_is_hidden_when_everything_has_an_owner(): void
    {
        $this->event('SW dentist', '2026-07-09 09:00');

        $this->assertFalse(Livewire::test('display.wall')->instance()->hasHouseholdEvents());
    }

    #[Test]
    public function each_members_name_and_colour_appear_on_the_wall(): void
    {
        $this->event('SW + JW dentist', '2026-07-09 09:00');

        Livewire::test('display.wall')
            ->assertSee('Simon')
            ->assertSee('Jenna')
            ->assertSee('#2563eb', escape: false)
            ->assertSee('#db2777', escape: false);
    }

    #[Test]
    public function coming_up_starts_from_tomorrow(): void
    {
        $this->event('Today thing', '2026-07-08 15:00');
        $this->event('Tomorrow thing', '2026-07-09 15:00');

        $upcoming = Livewire::test('display.wall')->instance()->upcoming();

        $this->assertSame(['Tomorrow thing'], $upcoming->pluck('event.title')->all());
    }

    #[Test]
    public function the_horizon_reaches_beyond_the_current_week(): void
    {
        $this->event('Next week thing', '2026-07-15 10:00');

        $days = collect(Livewire::test('display.wall')->instance()->days());

        $this->assertGreaterThanOrEqual(21, $days->count());
        $this->assertTrue(
            $days->firstWhere('date', '2026-07-15')['events']->contains(fn ($e) => $e->title === 'Next week thing')
        );
    }

    #[Test]
    public function the_display_renders_the_week_and_the_day_panels_together(): void
    {
        // Both are in the HTML so Alpine can switch between them with no round trip.
        $this->event('SW dentist', '2026-07-09 09:00');

        $html = Livewire::test('display.wall')->html();

        $this->assertStringContainsString("pickDay(", $html);
        $this->assertStringContainsString("showWeek()", $html);
        $this->assertStringContainsString('isPicked(', $html);
    }
}
