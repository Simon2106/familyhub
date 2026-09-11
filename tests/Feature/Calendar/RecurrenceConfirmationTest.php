<?php

namespace Tests\Feature\Calendar;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Assistant\AssistantTools;
use App\Services\Calendar\CalendarViews;
use App\Services\Calendar\EventWindow;
use App\Services\Search\HouseholdSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Do the readers show every occurrence of a repeating event, or only the first?
 *
 * Written first as a probe, which answered: only the first. Kept as a test,
 * because "the calendar shows every week" is the kind of thing that is obvious
 * until it silently is not.
 */
class RecurrenceConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-11 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'is_visible' => true,
        ]);

        $member = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey']);

        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Football training',
            'start_at' => '2026-09-11 17:00:00',
            'end_at' => '2026-09-11 18:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);

        $event->members()->attach($member->id);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The bug this whole change exists for, stated as assertions.
     *
     * Before: a weekly training appeared on one day of the month grid, zero
     * times the following week, and the assistant could not see it at all.
     */
    #[Test]
    public function every_reader_shows_every_occurrence(): void
    {
        $views = app(CalendarViews::class);
        $nextWeek = CarbonImmutable::parse('2026-09-18');

        // The month grid runs 31 Aug – 11 Oct, which holds five Fridays from
        // the 11th onwards.
        $month = $views->month($this->household, CarbonImmutable::parse('2026-09-01'));

        $days = collect($month['weeks'])->flatten(1)
            ->filter(fn ($day) => ($day['count'] ?? 0) > 0)
            ->count();

        $this->assertSame(5, $days, 'Every Friday in the grid, not just the first.');

        // The week after the series begins.
        $this->assertCount(
            1,
            app(EventWindow::class)->between($this->household, $nextWeek, $nextWeek->addDays(6)),
        );

        // And the assistant can answer a question about it.
        $said = app(AssistantTools::class)->run('calendar', [
            'from' => $nextWeek->toDateString(),
            'to' => $nextWeek->addDays(6)->toDateString(),
        ], $this->household);

        $this->assertStringContainsString('Football training', $said);
    }

    /**
     * Search stays one result per thing — nobody wants seventy-eight Fridays
     * — but the date it shows has to be an occurrence rather than whenever
     * the series was first put in the calendar.
     */
    #[Test]
    public function search_offers_the_series_once_dated_by_its_next_occurrence(): void
    {
        $results = app(HouseholdSearch::class)->search('Football', $this->household);

        $this->assertCount(1, $results);
        $this->assertSame('2026-09-11', $results->first()->date->format('Y-m-d'));

        // A week on, the next one is the following Friday.
        CarbonImmutable::setTestNow('2026-09-15 09:00:00');

        $later = app(HouseholdSearch::class)->search('Football', $this->household);

        $this->assertSame('2026-09-18', $later->first()->date->format('Y-m-d'));
    }
}
