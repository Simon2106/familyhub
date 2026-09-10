<?php

namespace Tests\Feature\Display;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Month and day, on the wall and on the phone. */
class MonthAndDayViewTest extends TestCase
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

        $member = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'member_id' => $member->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /* -------------------------------- wall ------------------------------- */

    #[Test]
    public function the_wall_offers_a_month_and_pages_through_it(): void
    {
        Livewire::test('display.wall')
            ->assertSee('September 2026')
            ->call('shiftMonth', 1)
            ->assertSee('October 2026')
            ->call('shiftMonth', -2)
            ->assertSee('August 2026');
    }

    #[Test]
    public function paging_stops_before_it_becomes_a_stuck_button(): void
    {
        $component = Livewire::test('display.wall');

        foreach (range(1, 40) as $ignored) {
            $component->call('shiftMonth', 1);
        }

        $this->assertSame(24, $component->get('monthOffset'));
    }

    #[Test]
    public function the_wall_timeline_shows_whichever_day_it_was_given(): void
    {
        // Including one outside the fortnight the columns are loaded for,
        // which is the whole reason a month tap opens the timeline.
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Sports day',
            'start_at' => '2026-11-20 09:00:00',
            'end_at' => '2026-11-20 15:00:00',
        ]);

        Livewire::test('display.wall')
            ->set('timelineDate', '2026-11-20')
            ->assertSee('Sports day')
            ->assertSee('Friday 20 November');
    }

    #[Test]
    public function the_timeline_falls_back_to_today_rather_than_breaking(): void
    {
        Livewire::test('display.wall')
            ->set('timelineDate', null)
            ->assertSee('Wednesday 9 September');
    }

    /* ------------------------------- phone ------------------------------- */

    #[Test]
    public function the_phone_switches_between_agenda_month_and_day(): void
    {
        Livewire::test('phone.home')
            ->assertSet('view', 'agenda')
            ->assertSee('Agenda')
            ->call('showView', 'month')
            ->assertSet('view', 'month')
            ->assertSee('September 2026')
            ->call('showView', 'day')
            ->assertSet('view', 'day');
    }

    #[Test]
    public function a_view_nobody_offers_falls_back_to_the_agenda(): void
    {
        Livewire::test('phone.home')
            ->call('showView', 'interpretive dance')
            ->assertSet('view', 'agenda');
    }

    #[Test]
    public function tapping_a_day_in_the_month_opens_that_day(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Parents evening',
            'start_at' => '2026-09-24 18:00:00',
            'end_at' => '2026-09-24 19:00:00',
        ]);

        Livewire::test('phone.home')
            ->call('showView', 'month')
            ->call('openDay', '2026-09-24')
            ->assertSet('view', 'day')
            ->assertSee('Parents evening');
    }

    #[Test]
    public function the_day_steps_forwards_and_back(): void
    {
        Livewire::test('phone.home')
            ->call('showView', 'day')
            ->assertSee('Wed 9 Sep')
            ->call('shiftDay', 1)
            ->assertSee('Thu 10 Sep')
            ->call('shiftDay', -2)
            ->assertSee('Tue 8 Sep');
    }

    /* ------------------------------- shared ------------------------------ */

    #[Test]
    public function both_remember_the_view_on_the_device_rather_than_the_household(): void
    {
        // The wall in the kitchen and a phone previewing it want different
        // things open, and neither should be set again every morning.
        $wall = Livewire::test('display.wall')->html();
        $phone = Livewire::test('phone.home')->html();

        $this->assertStringContainsString("localStorage.getItem('familyhub.view')", $wall);
        $this->assertStringContainsString("localStorage.getItem('familyhub.phone-view')", $phone);
    }
}
