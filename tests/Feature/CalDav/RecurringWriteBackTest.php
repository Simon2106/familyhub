<?php

namespace Tests\Feature\CalDav;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Household;
use App\Services\Attribution\EventAttributor;
use App\Services\CalDav\CalDavClient;
use App\Services\CalDav\EventMapper;
use App\Services\CalDav\EventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Writing a repeating event back to iCloud.
 *
 * A CalDAV resource is a whole recurrence set — the master VEVENT and every
 * edited occurrence, all sharing a UID — and a PUT replaces the file. So the
 * thing that matters most here is what goes *into* the PUT: sending one VEVENT
 * to a series' href silently deletes every exception the family ever made.
 */
class RecurringWriteBackTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    /** What the last PUT actually sent. */
    protected ?string $lastPut = null;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-11 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'is_visible' => true, 'is_writable' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function writer(): EventWriter
    {
        $client = Mockery::mock(CalDavClient::class);

        $client->shouldReceive('put')
            ->andReturnUsing(function ($href, $ics) {
                $this->lastPut = $ics;

                return '"etag-after"';
            });

        $client->shouldReceive('delete')->andReturnNull();

        return new EventWriter($client, new EventMapper, app(EventAttributor::class));
    }

    protected function weekly(): Event
    {
        return Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'training@uid',
            'href' => '/cal/training.ics',
            'etag' => '"etag-before"',
            'recurrence_id' => null,
            'title' => 'Football training',
            'start_at' => '2026-09-11 17:00:00',
            'end_at' => '2026-09-11 18:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);
    }

    /* ------------------------- one occurrence --------------------------- */

    #[Test]
    public function editing_one_occurrence_makes_an_override_and_leaves_the_series(): void
    {
        $master = $this->weekly();

        $override = $this->writer()->updateOccurrence(
            $master,
            CarbonImmutable::parse('2026-09-18 17:00:00'),
            ['title' => 'Away at Chalfont', 'start_at' => CarbonImmutable::parse('2026-09-18 19:30:00'),
                'end_at' => CarbonImmutable::parse('2026-09-18 21:00:00')],
        );

        $this->assertTrue($override->isOverride());
        $this->assertSame('Away at Chalfont', $override->title);
        $this->assertNull($override->rrule, 'An override is one occurrence, not a rule.');

        // The series itself is untouched.
        $this->assertSame('Football training', $master->fresh()->title);
        $this->assertSame('FREQ=WEEKLY;BYDAY=FR', $master->fresh()->rrule);
    }

    /**
     * The one that would quietly destroy somebody's calendar.
     */
    #[Test]
    public function the_put_carries_the_master_and_every_override(): void
    {
        $master = $this->weekly();

        $this->writer()->updateOccurrence(
            $master,
            CarbonImmutable::parse('2026-09-18 17:00:00'),
            ['title' => 'Away at Chalfont'],
        );

        $this->assertSame(2, substr_count($this->lastPut, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=FR', $this->lastPut);
        $this->assertStringContainsString('RECURRENCE-ID', $this->lastPut);
        $this->assertStringContainsString('Away at Chalfont', $this->lastPut);
        $this->assertStringContainsString('Football training', $this->lastPut);
    }

    #[Test]
    public function editing_the_same_occurrence_twice_updates_one_override(): void
    {
        $master = $this->weekly();
        $when = CarbonImmutable::parse('2026-09-18 17:00:00');

        $this->writer()->updateOccurrence($master, $when, ['title' => 'First go']);
        $this->writer()->updateOccurrence($master->fresh(), $when, ['title' => 'Second go']);

        $overrides = Event::whereNotNull('recurrence_id')->get();

        $this->assertCount(1, $overrides);
        $this->assertSame('Second go', $overrides->first()->title);
        $this->assertSame(2, substr_count($this->lastPut, 'BEGIN:VEVENT'));
    }

    #[Test]
    public function the_edited_occurrence_shows_on_the_right_day(): void
    {
        $master = $this->weekly();

        $this->writer()->updateOccurrence(
            $master,
            CarbonImmutable::parse('2026-09-18 17:00:00'),
            ['title' => 'Away at Chalfont', 'start_at' => CarbonImmutable::parse('2026-09-18 19:30:00'),
                'end_at' => CarbonImmutable::parse('2026-09-18 21:00:00')],
        );

        $days = EventOccurrence::orderBy('starts_at')->pluck('starts_at')
            ->map(fn ($m) => CarbonImmutable::parse($m)->utc()->format('Y-m-d H:i'))->all();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertContains('2026-09-18 19:30', $days);
        $this->assertContains('2026-09-25 17:00', $days, 'And the week after is untouched.');
    }

    /* --------------------- deleting one occurrence ---------------------- */

    #[Test]
    public function deleting_one_occurrence_strikes_the_date_out(): void
    {
        $master = $this->weekly();

        $this->writer()->deleteOccurrence($master, CarbonImmutable::parse('2026-09-18 17:00:00'));

        $this->assertCount(1, $master->fresh()->exdate);
        $this->assertStringContainsString('EXDATE', $this->lastPut);

        // The series survives; the one week does not.
        $this->assertNotNull($master->fresh());

        $days = EventOccurrence::pluck('starts_at')
            ->map(fn ($m) => CarbonImmutable::parse($m)->utc()->format('Y-m-d H:i'))->all();

        $this->assertNotContains('2026-09-18 17:00', $days);
        $this->assertContains('2026-09-25 17:00', $days);
    }

    /**
     * Otherwise the struck-out date would come back wearing the override's
     * clothes the next time the series was expanded.
     */
    #[Test]
    public function deleting_an_occurrence_that_had_been_edited_removes_the_override_too(): void
    {
        $master = $this->weekly();
        $when = CarbonImmutable::parse('2026-09-18 17:00:00');

        $this->writer()->updateOccurrence($master, $when, ['title' => 'Away at Chalfont']);
        $this->writer()->deleteOccurrence($master->fresh(), $when);

        $this->assertSame(0, Event::whereNotNull('recurrence_id')->count());

        $days = EventOccurrence::pluck('starts_at')
            ->map(fn ($m) => CarbonImmutable::parse($m)->utc()->format('Y-m-d H:i'))->all();

        $this->assertNotContains('2026-09-18 17:00', $days);
    }

    /* --------------------------- the series ----------------------------- */

    #[Test]
    public function deleting_the_series_takes_its_overrides_with_it(): void
    {
        $master = $this->weekly();

        $this->writer()->updateOccurrence(
            $master,
            CarbonImmutable::parse('2026-09-18 17:00:00'),
            ['title' => 'Away at Chalfont'],
        );

        $this->writer()->delete($master->fresh());

        $this->assertSame(0, Event::count());
        $this->assertSame(0, EventOccurrence::count());
    }

    #[Test]
    public function editing_the_series_still_sends_its_overrides(): void
    {
        $master = $this->weekly();

        $this->writer()->updateOccurrence(
            $master,
            CarbonImmutable::parse('2026-09-18 17:00:00'),
            ['title' => 'Away at Chalfont'],
        );

        $this->writer()->update($master->fresh(), ['title' => 'Football training (new time)']);

        $this->assertSame(2, substr_count($this->lastPut, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Away at Chalfont', $this->lastPut);
        $this->assertStringContainsString('Football training (new time)', $this->lastPut);
    }

    #[Test]
    public function a_struck_out_date_survives_a_write(): void
    {
        $master = $this->weekly();

        $this->writer()->deleteOccurrence($master, CarbonImmutable::parse('2026-09-18 17:00:00'));
        $this->writer()->update($master->fresh(), ['title' => 'Renamed']);

        $this->assertStringContainsString('EXDATE', $this->lastPut);
    }

    /** A plain event is not a series, and nothing clever should happen to it. */
    #[Test]
    public function a_plain_event_is_written_as_one_vevent(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'dentist@uid',
            'href' => '/cal/dentist.ics',
            'title' => 'Dentist',
            'start_at' => '2026-09-15 09:00:00',
            'end_at' => '2026-09-15 09:30:00',
        ]);

        $this->writer()->update($event, ['title' => 'Dentist, moved']);

        $this->assertSame(1, substr_count($this->lastPut, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString('RRULE', $this->lastPut);
    }

    #[Test]
    public function editing_one_occurrence_of_a_non_repeating_event_just_edits_it(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'external_id' => 'dentist@uid',
            'href' => '/cal/dentist.ics',
            'title' => 'Dentist',
            'start_at' => '2026-09-15 09:00:00',
            'end_at' => '2026-09-15 09:30:00',
        ]);

        $this->writer()->updateOccurrence(
            $event,
            CarbonImmutable::parse('2026-09-15 09:00:00'),
            ['title' => 'Dentist, moved'],
        );

        $this->assertSame(0, Event::whereNotNull('recurrence_id')->count());
        $this->assertSame('Dentist, moved', $event->fresh()->title);
    }
}
