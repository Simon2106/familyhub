<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rows are stored in UTC; the family lives in Europe/London. Which day an event
 * lands on is decided by the household's midnight, never the server's.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $member;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->member = Member::factory()->create(['household_id' => $this->household->id]);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => $this->member->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_app_stores_times_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    #[Test]
    public function an_early_morning_event_lands_on_the_local_day_not_the_utc_one(): void
    {
        // British Summer Time: 00:30 on 8 July in London is 23:30 on 7 July UTC.
        // Bucketing on the UTC value would show this on the 7th.
        Carbon::setTestNow('2026-07-07 12:00:00');

        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Midnight feast',
            'start_at' => Carbon::parse('2026-07-07 23:30:00', 'UTC'),
            'end_at' => Carbon::parse('2026-07-08 00:30:00', 'UTC'),
        ]);

        $days = collect(Livewire::test('display.wall')->instance()->days())
            ->filter(fn (array $d) => $d['events_by_member']->flatten()->contains(
                fn (Event $e) => $e->title === 'Midnight feast'
            ))
            ->pluck('date')
            ->values()
            ->all();

        $this->assertContains('2026-07-08', $days, 'A 00:30 London event must appear on the 8th.');
    }

    #[Test]
    public function event_times_are_displayed_in_household_time(): void
    {
        // 08:15 London during BST is 07:15 UTC. The wall must read 08:15.
        Carbon::setTestNow('2026-07-07 06:00:00');

        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'School run',
            'start_at' => Carbon::parse('2026-07-07 07:15:00', 'UTC'),
            'end_at' => Carbon::parse('2026-07-07 07:45:00', 'UTC'),
        ]);

        Livewire::test('display.wall')
            ->assertSee('08:15')
            ->assertDontSee('07:15');
    }

    #[Test]
    public function a_local_time_is_converted_to_utc_on_the_way_into_the_database(): void
    {
        // 08:15 in London during BST must land as 07:15 UTC, not 08:15 UTC.
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'start_at' => Carbon::parse('2026-07-07 08:15:00', 'Europe/London'),
            'end_at' => Carbon::parse('2026-07-07 08:45:00', 'Europe/London'),
        ]);

        $this->assertSame(
            '2026-07-07 07:15:00',
            DB::table('events')->where('id', $event->id)->value('start_at'),
        );

        $this->assertSame('07:15', $event->fresh()->start_at->format('H:i'));
        $this->assertSame('08:15', $event->fresh()->start_at->timezone('Europe/London')->format('H:i'));
    }

    #[Test]
    public function a_winter_time_needs_no_shift(): void
    {
        // GMT === UTC, so a January 08:15 stays 08:15.
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'start_at' => Carbon::parse('2026-01-14 08:15:00', 'Europe/London'),
            'end_at' => Carbon::parse('2026-01-14 08:45:00', 'Europe/London'),
        ]);

        $this->assertSame('08:15', $event->fresh()->start_at->format('H:i'));
    }

    #[Test]
    public function it_falls_back_to_the_configured_zone_when_the_household_has_none(): void
    {
        $this->household->update(['timezone' => null]);

        $this->assertSame(
            config('familyhub.timezone'),
            $this->household->fresh()->displayTimezone(),
        );
    }

    #[Test]
    public function todays_boundary_follows_the_household_zone(): void
    {
        // 23:45 UTC on 6 September is already 00:45 on the 7th in London (BST).
        Carbon::setTestNow(Carbon::parse('2026-09-06 23:45:00', 'UTC'));

        $this->assertSame('2026-09-07', $this->household->todayLocal()->toDateString());
    }
}
