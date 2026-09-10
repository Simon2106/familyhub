<?php

namespace Tests\Feature\Display;

use App\Models\BinCollection;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Models\SchoolDate;
use App\Services\Calendar\CalendarViews;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A month at a glance, and a day hour by hour. */
class CalendarViewsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Simon', 'colour' => '#2563eb',
        ]);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'member_id' => $this->simon->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function views(): CalendarViews
    {
        return app(CalendarViews::class);
    }

    protected function on(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/London');
    }

    protected function event(string $title, string $from, string $to, array $attributes = []): Event
    {
        $event = Event::factory()->create($attributes + [
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => $from,
            'end_at' => $to,
        ]);

        $event->members()->attach($this->simon->id);

        return $event->fresh();
    }

    /* ------------------------------- month ------------------------------- */

    #[Test]
    public function a_month_is_always_six_rows_of_seven(): void
    {
        // So the grid does not change height from month to month and the wall
        // does not reflow around it.
        foreach (['2026-02-01', '2026-09-01', '2027-05-01'] as $anchor) {
            $month = $this->views()->month($this->household, $this->on($anchor));

            $this->assertCount(6, $month['weeks']);

            foreach ($month['weeks'] as $week) {
                $this->assertCount(7, $week);
            }
        }
    }

    #[Test]
    public function it_starts_on_a_monday_so_it_lines_up_with_the_week_view(): void
    {
        $month = $this->views()->month($this->household, $this->on('2026-09-15'));

        $this->assertSame('Mon', $month['weeks'][0][0]['carbon']->format('D'));
        // 1 September 2026 is a Tuesday, so the grid opens on 31 August.
        $this->assertSame('2026-08-31', $month['weeks'][0][0]['date']);
        $this->assertFalse($month['weeks'][0][0]['in_month']);
    }

    #[Test]
    public function a_day_shows_one_dot_per_person_not_one_per_event(): void
    {
        // A day with four dentist appointments is still one busy day.
        $this->event('Dentist', '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        $this->event('Haircut', '2026-09-15 10:00:00', '2026-09-15 11:00:00');

        $day = $this->dayIn($this->views()->month($this->household, $this->on('2026-09-15')), '2026-09-15');

        $this->assertSame(2, $day['count']);
        $this->assertSame(['#2563eb'], $day['colours']);
    }

    #[Test]
    public function an_event_nobody_is_attached_to_gets_the_household_colour(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Bin day',
            'start_at' => '2026-09-16 07:00:00',
            'end_at' => '2026-09-16 08:00:00',
        ]);

        $day = $this->dayIn($this->views()->month($this->household, $this->on('2026-09-15')), '2026-09-16');

        $this->assertSame(['#94a3b8'], $day['colours']);
    }

    #[Test]
    public function the_bands_the_household_lives_by_carry_into_the_month(): void
    {
        // "Is that a school day?" is a question people ask of a month.
        $school = Place::factory()->school()->create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate', 'short_code' => 'SG',
        ]);

        SchoolDate::factory()->create([
            'place_id' => $school->id, 'kind' => 'inset',
            'name' => 'INSET day', 'starts_on' => '2026-09-18', 'ends_on' => '2026-09-18',
        ]);

        BinCollection::factory()->create([
            'household_id' => $this->household->id, 'on' => '2026-09-15', 'kind' => 'recycling',
        ]);

        $month = $this->views()->month($this->household, $this->on('2026-09-15'));

        $this->assertCount(1, $this->dayIn($month, '2026-09-18')['closures']);
        $this->assertSame('SG', $this->dayIn($month, '2026-09-18')['closures'][0]->code);
        $this->assertCount(1, $this->dayIn($month, '2026-09-15')['bins']);
        $this->assertCount(0, $this->dayIn($month, '2026-09-16')['bins']);
    }

    #[Test]
    public function today_is_marked_and_so_is_what_has_gone(): void
    {
        $month = $this->views()->month($this->household, $this->on('2026-09-15'));

        $this->assertTrue($this->dayIn($month, '2026-09-09')['is_today']);
        $this->assertTrue($this->dayIn($month, '2026-09-08')['is_past']);
        $this->assertFalse($this->dayIn($month, '2026-09-10')['is_past']);
    }

    /* -------------------------------- day -------------------------------- */

    #[Test]
    public function all_day_things_sit_above_the_timeline_rather_than_in_it(): void
    {
        $this->event('Half term', '2026-09-15 00:00:00', '2026-09-15 23:59:00', ['all_day' => true]);
        $this->event('Dentist', '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $day = $this->views()->day($this->household, $this->on('2026-09-15'));

        $this->assertCount(1, $day['all_day']);
        $this->assertSame('Half term', $day['all_day'][0]->title);
        $this->assertCount(1, $day['timed']);
        $this->assertSame('Dentist', $day['timed'][0]['event']->title);
    }

    #[Test]
    public function a_quiet_day_shows_working_hours_rather_than_all_of_midnight_to_midnight(): void
    {
        $day = $this->views()->day($this->household, $this->on('2026-09-15'));

        $this->assertSame(CalendarViews::DAY_FROM, $day['from']);
        $this->assertSame(CalendarViews::DAY_TO, $day['to']);
    }

    #[Test]
    public function but_it_grows_to_fit_an_early_flight_and_a_late_pick_up(): void
    {
        // Both have to be on the screen; a fixed range would put one off it.
        $this->event('Flight', '2026-09-15 04:30:00', '2026-09-15 06:00:00');
        $this->event('Pick-up', '2026-09-15 21:30:00', '2026-09-15 22:45:00');

        $day = $this->views()->day($this->household, $this->on('2026-09-15'));

        $this->assertLessThanOrEqual(5, $day['from'], 'Early enough for the flight.');
        $this->assertGreaterThanOrEqual(23, $day['to'], 'Late enough for the pick-up.');
    }

    #[Test]
    public function an_event_is_placed_as_a_percentage_of_the_visible_day(): void
    {
        // The same numbers place it on a phone and on a wall.
        $this->event('Lunch', '2026-09-15 11:00:00', '2026-09-15 12:00:00');

        $placed = $this->views()->day($this->household, $this->on('2026-09-15'))['timed'][0];

        // 07:00–22:00 is fifteen hours; noon local (11:00 UTC) is five in.
        $this->assertEqualsWithDelta(100 * 5 / 15, $placed['top'], 0.01);
        $this->assertEqualsWithDelta(100 * 1 / 15, $placed['height'], 0.01);
    }

    #[Test]
    public function something_that_began_yesterday_starts_at_the_top(): void
    {
        // Rather than somewhere above the screen.
        $this->event('Night shift', '2026-09-14 21:00:00', '2026-09-15 06:00:00');

        $placed = $this->views()->day($this->household, $this->on('2026-09-15'))['timed'][0];

        $this->assertSame(0.0, $placed['top']);
        $this->assertGreaterThan(0, $placed['height']);
    }

    #[Test]
    public function a_moment_long_event_is_still_tall_enough_to_read(): void
    {
        $this->event('Tablet', '2026-09-15 08:00:00', '2026-09-15 08:00:00');

        $placed = $this->views()->day($this->household, $this->on('2026-09-15'))['timed'][0];

        $this->assertGreaterThan(0, $placed['height']);
    }

    #[Test]
    public function the_bands_carry_into_the_day_too(): void
    {
        BinCollection::factory()->create([
            'household_id' => $this->household->id, 'on' => '2026-09-15', 'kind' => 'food',
        ]);

        $this->assertCount(1, $this->views()->day($this->household, $this->on('2026-09-15'))['bins']);
    }

    /** @param array{weeks: list<list<array<string, mixed>>>} $month */
    protected function dayIn(array $month, string $date): array
    {
        foreach ($month['weeks'] as $week) {
            foreach ($week as $day) {
                if ($day['date'] === $date) {
                    return $day;
                }
            }
        }

        $this->fail("{$date} is not in this month grid.");
    }
}
