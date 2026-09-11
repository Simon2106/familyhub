<?php

namespace Tests\Feature\Calendar;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Household;
use App\Services\CalDav\EventMapper;
use App\Services\Calendar\EventWindow;
use App\Services\Calendar\OccurrenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A repeating event, turned into the days it actually happens on.
 *
 * The three cases worth being careful about are a plain weekly rule, a week
 * struck out with EXDATE, and a single occurrence edited on its own — because
 * those are what iCloud produces when somebody taps "delete just this one" or
 * "change just this one", and getting any of them wrong leaves a wall showing
 * something that is not true.
 */
class OccurrenceExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    /** A Friday. */
    protected const NOW = '2026-09-11 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOW);

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'is_visible' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function store(): OccurrenceStore
    {
        return app(OccurrenceStore::class);
    }

    protected function weekly(array $attributes = []): Event
    {
        return Event::factory()->create($attributes + [
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => null,
            'title' => 'Football training',
            'start_at' => '2026-09-11 17:00:00',
            'end_at' => '2026-09-11 18:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);
    }

    /** @return list<string> */
    protected function occurrenceDays(): array
    {
        return EventOccurrence::orderBy('starts_at')
            ->pluck('starts_at')
            ->map(fn ($m) => CarbonImmutable::parse($m)->utc()->format('Y-m-d H:i'))
            ->all();
    }

    /* ------------------------------ weekly ------------------------------ */

    #[Test]
    public function a_weekly_rule_becomes_one_row_per_week(): void
    {
        $written = $this->store()->rebuildSeries($this->weekly());

        $days = $this->occurrenceDays();

        $this->assertGreaterThan(50, $written, 'Eighteen months of Fridays.');
        $this->assertSame(
            ['2026-09-11 17:00', '2026-09-18 17:00', '2026-09-25 17:00', '2026-10-02 17:00'],
            array_slice($days, 0, 4),
        );
    }

    #[Test]
    public function every_occurrence_keeps_the_length_of_the_first(): void
    {
        $this->store()->rebuildSeries($this->weekly());

        $second = EventOccurrence::orderBy('starts_at')->skip(1)->first();

        $this->assertSame('2026-09-18 17:00', $second->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-09-18 18:00', $second->ends_at->utc()->format('Y-m-d H:i'));
    }

    #[Test]
    public function the_window_is_a_month_back_and_eighteen_months_on(): void
    {
        $this->store()->rebuildSeries($this->weekly([
            'start_at' => '2020-01-03 17:00:00',
            'end_at' => '2020-01-03 18:00:00',
        ]));

        $days = $this->occurrenceDays();

        $this->assertGreaterThanOrEqual('2026-08-11', substr($days[0], 0, 10), 'Nothing from 2020.');
        $this->assertLessThanOrEqual('2028-03-12', substr(end($days), 0, 10), 'And nothing in 2030.');
    }

    /** An event that does not repeat still gets a row, so readers ask one table. */
    #[Test]
    public function a_plain_event_has_exactly_one_occurrence(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Dentist',
            'start_at' => '2026-09-15 09:00:00',
            'end_at' => '2026-09-15 09:30:00',
        ]);

        $this->store()->rebuildSeries($event);

        $this->assertSame(['2026-09-15 09:00'], $this->occurrenceDays());
        $this->assertFalse(EventOccurrence::first()->is_override);
    }

    /* ------------------------------ EXDATE ------------------------------ */

    /** "Delete just this one" — iCloud strikes the date out of the series. */
    #[Test]
    public function a_date_struck_out_produces_no_occurrence(): void
    {
        $this->store()->rebuildSeries($this->weekly([
            'exdate' => ['2026-09-18T17:00:00+00:00'],
        ]));

        $days = $this->occurrenceDays();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertContains('2026-09-11 17:00', $days);
        $this->assertContains('2026-09-25 17:00', $days);
    }

    #[Test]
    public function several_struck_out_dates_are_all_honoured(): void
    {
        $this->store()->rebuildSeries($this->weekly([
            'exdate' => ['2026-09-18T17:00:00+00:00', '2026-10-02T17:00:00+00:00'],
        ]));

        $days = $this->occurrenceDays();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertNotContains('2026-10-02 17:00', $days);
        $this->assertContains('2026-09-25 17:00', $days);
    }

    /** An EXDATE written in another timezone is the same occurrence. */
    #[Test]
    public function a_struck_out_date_matches_whatever_zone_it_was_written_in(): void
    {
        // 18:00 in London is 17:00 UTC — the same Friday training.
        $this->store()->rebuildSeries($this->weekly([
            'exdate' => ['2026-09-18T18:00:00+01:00'],
        ]));

        $this->assertNotContains('2026-09-18 17:00', $this->occurrenceDays());
    }

    #[Test]
    public function an_unreadable_exdate_does_not_take_the_series_with_it(): void
    {
        $this->store()->rebuildSeries($this->weekly(['exdate' => ['not a date at all']]));

        $this->assertContains('2026-09-11 17:00', $this->occurrenceDays());
    }

    /* ----------------------------- overrides ---------------------------- */

    /** "Change just this one" — a second VEVENT sharing the UID. */
    #[Test]
    public function an_edited_occurrence_replaces_the_generated_one(): void
    {
        $master = $this->weekly();

        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => '2026-09-18T17:00:00Z',
            'title' => 'Football training (away at Chalfont)',
            'start_at' => '2026-09-18 19:30:00',
            'end_at' => '2026-09-18 21:00:00',
            'location' => 'Chalfont',
        ]);

        $this->store()->rebuildSeries($master);

        $days = $this->occurrenceDays();

        $this->assertNotContains('2026-09-18 17:00', $days, 'The generated one is gone…');
        $this->assertContains('2026-09-18 19:30', $days, '…and the edited one is there.');

        $moved = EventOccurrence::where('starts_at', '2026-09-18 19:30:00')->first();

        $this->assertTrue($moved->is_override);
        $this->assertSame('Football training (away at Chalfont)', $moved->title);
        $this->assertSame('Chalfont', $moved->location);
        $this->assertSame('2026-09-18 21:00', $moved->ends_at->utc()->format('Y-m-d H:i'));

        // The tap has to open the row somebody would be editing.
        $this->assertSame(
            Event::where('recurrence_id', '2026-09-18T17:00:00Z')->value('id'),
            $moved->event_id,
        );

        // And it still belongs to the series, so a rebuild clears it.
        $this->assertSame($master->id, $moved->series_event_id);
    }

    #[Test]
    public function an_occurrence_moved_to_another_day_moves(): void
    {
        $master = $this->weekly();

        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => '2026-09-18T17:00:00Z',
            'title' => 'Football training (Sunday this week)',
            'start_at' => '2026-09-20 10:00:00',
            'end_at' => '2026-09-20 11:00:00',
        ]);

        $this->store()->rebuildSeries($master);

        $days = $this->occurrenceDays();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertContains('2026-09-20 10:00', $days);
    }

    /** iCloud sometimes cancels one occurrence rather than striking it out. */
    #[Test]
    public function a_cancelled_override_removes_the_occurrence(): void
    {
        $master = $this->weekly();

        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => '2026-09-18T17:00:00Z',
            'title' => 'Football training',
            'start_at' => '2026-09-18 17:00:00',
            'end_at' => '2026-09-18 18:00:00',
            'status' => 'cancelled',
        ]);

        $this->store()->rebuildSeries($master);

        $days = $this->occurrenceDays();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertContains('2026-09-25 17:00', $days);
    }

    #[Test]
    public function an_override_moved_onto_another_occurrence_does_not_double_up(): void
    {
        $master = $this->weekly();

        // Moved onto the 25th, which the rule already lands on.
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => '2026-09-18T17:00:00Z',
            'title' => 'Doubled up',
            'start_at' => '2026-09-25 17:00:00',
            'end_at' => '2026-09-25 18:00:00',
        ]);

        $this->store()->rebuildSeries($master);

        $onThatDay = EventOccurrence::where('starts_at', '2026-09-25 17:00:00')->get();

        $this->assertCount(1, $onThatDay);
        $this->assertSame('Doubled up', $onThatDay->first()->title, 'The edited one wins.');
    }

    /* ------------------------------ rebuild ----------------------------- */

    #[Test]
    public function rebuilding_replaces_rather_than_adds(): void
    {
        $master = $this->weekly();

        $first = $this->store()->rebuildSeries($master);
        $second = $this->store()->rebuildSeries($master);

        $this->assertSame($first, $second);
        $this->assertSame($first, EventOccurrence::count());
    }

    #[Test]
    public function rebuilding_from_an_override_rebuilds_the_whole_series(): void
    {
        $this->weekly();

        $override = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'recurrence_id' => '2026-09-18T17:00:00Z',
            'title' => 'Edited',
            'start_at' => '2026-09-18 19:30:00',
            'end_at' => '2026-09-18 20:30:00',
        ]);

        $this->store()->rebuildFor($override);

        $this->assertGreaterThan(1, EventOccurrence::count());
        $this->assertContains('2026-09-18 19:30', $this->occurrenceDays());
    }

    #[Test]
    public function deleting_the_event_takes_its_occurrences_with_it(): void
    {
        $master = $this->weekly();

        $this->store()->rebuildSeries($master);
        $this->assertGreaterThan(0, EventOccurrence::count());

        $master->delete();

        $this->assertSame(0, EventOccurrence::count());
    }

    /* ------------------------------ the guard --------------------------- */

    /**
     * A copy handed out for display must never be saved: it carries one
     * Tuesday's time, and saving it would write that over the whole series.
     */
    #[Test]
    public function an_occurrence_copy_refuses_to_be_saved(): void
    {
        $this->store()->rebuildSeries($this->weekly());

        $events = app(EventWindow::class)->between(
            $this->household,
            CarbonImmutable::parse('2026-09-18'),
            CarbonImmutable::parse('2026-09-19'),
        );

        $this->assertCount(1, $events);

        $copy = $events->first();

        $this->assertSame('2026-09-18 17:00', $copy->start_at->utc()->format('Y-m-d H:i'));

        $this->expectException(LogicException::class);

        $copy->save();
    }

    /* ------------------------------ mapping ----------------------------- */

    #[Test]
    public function exdates_are_read_off_the_wire(): void
    {
        $ics = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Test//EN\nBEGIN:VEVENT\n"
            ."UID:training@uid\nSUMMARY:Football training\n"
            ."DTSTART:20260911T170000Z\nDTEND:20260911T180000Z\n"
            ."RRULE:FREQ=WEEKLY;BYDAY=FR\n"
            ."EXDATE:20260918T170000Z\nEXDATE:20261002T170000Z\n"
            ."END:VEVENT\nEND:VCALENDAR\n";

        $mapped = app(EventMapper::class)->fromIcs($ics);

        $this->assertCount(1, $mapped);
        $this->assertCount(2, $mapped[0]['exdate']);
        $this->assertStringStartsWith('2026-09-18T17:00', $mapped[0]['exdate'][0]);
    }
}
