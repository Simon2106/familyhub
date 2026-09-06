<?php

namespace Tests\Feature\CalDav;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Services\CalDav\CalDavManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected CalendarAccount $account;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-01 09:00:00');

        $household = Household::factory()->create();
        $this->account = CalendarAccount::factory()->create(['household_id' => $household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $this->account->id,
            'external_id' => FakeICloud::CALENDAR,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function sync(bool $force = false): \App\Services\CalDav\SyncResult
    {
        return app(CalDavManager::class)->sync($this->account)->sync($this->calendar->fresh(), $force);
    }

    #[Test]
    public function a_full_sync_imports_events(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500'),
            '/12345678/calendars/home/dentist.ics' => FakeICloud::event('dentist-1', 'Dentist', '20260708T090000', '20260708T093000'),
        ])]);

        $result = $this->sync();

        $this->assertSame(2, $result->created);
        $this->assertSame(2, Event::count());
        $this->assertDatabaseHas('events', ['external_id' => 'swim-1', 'title' => 'Swimming']);
    }

    #[Test]
    public function it_normalises_british_summer_time_to_utc(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500'),
        ])]);

        $this->sync();

        // 16:30 London in July is 15:30 UTC.
        $this->assertSame('15:30', Event::first()->start_at->format('H:i'));
        $this->assertSame('16:30', Event::first()->start_at->timezone('Europe/London')->format('H:i'));
    }

    #[Test]
    public function an_all_day_events_exclusive_end_is_pulled_back_into_the_day(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/inset.ics' => FakeICloud::allDayEvent('inset-1', 'Inset day', '20260709', '20260710'),
        ])]);

        $this->sync();

        $event = Event::first();

        // An ICS all-day DTEND is exclusive; a one-day event must not appear
        // to span two days on the wall.
        $this->assertTrue($event->all_day);
        $this->assertSame('2026-07-09', $event->start_at->toDateString());
        $this->assertSame('2026-07-09', $event->end_at->toDateString());
    }

    #[Test]
    public function a_recurring_event_and_its_exception_are_stored_separately(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::recurringWithException('swim-1'),
        ])]);

        $this->sync();

        // Same UID, one resource, two rows — the master and the override.
        $this->assertSame(2, Event::where('external_id', 'swim-1')->count());

        $master = Event::whereNull('recurrence_id')->firstOrFail();
        $override = Event::whereNotNull('recurrence_id')->firstOrFail();

        $this->assertSame('FREQ=WEEKLY;BYDAY=TU', $master->rrule);
        $this->assertNull($override->rrule);
        $this->assertSame('20260714T153000Z', $override->recurrence_id);
        $this->assertSame('Swimming lesson (later)', $override->title);
    }

    #[Test]
    public function re_syncing_the_same_event_updates_rather_than_duplicating(): void
    {
        $ics = FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500');
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus(['/12345678/calendars/home/swim.ics' => $ics])]);

        $this->sync();
        $first = Event::firstOrFail();

        // Same UID, renamed upstream.
        $renamed = FakeICloud::event('swim-1', 'Swimming lesson', '20260707T163000', '20260707T171500');
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus(['/12345678/calendars/home/swim.ics' => $renamed])]);

        $result = $this->sync();

        $this->assertSame(1, Event::count(), 'Duplicate detection must key on (calendar, uid, recurrence-id).');
        $this->assertSame(1, $result->updated);
        $this->assertSame($first->id, Event::first()->id);
        $this->assertSame('Swimming lesson', Event::first()->title);
    }

    #[Test]
    public function an_unchanged_event_is_left_alone(): void
    {
        $ics = FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500');
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus(['/12345678/calendars/home/swim.ics' => $ics])]);

        $this->sync();
        $result = $this->sync();

        $this->assertSame(1, $result->unchanged);
        $this->assertSame(0, $result->updated);
    }

    #[Test]
    public function an_incremental_sync_uses_the_stored_token(): void
    {
        $this->calendar->update(['supports_sync_collection' => true, 'sync_token' => 'token-1']);

        FakeICloud::fake(['sync-collection' => FakeICloud::multiStatus(
            ['/12345678/calendars/home/new.ics' => FakeICloud::event('new-1', 'New thing', '20260710T100000', '20260710T110000')],
            syncToken: 'token-2',
        )]);

        $result = $this->sync();

        $this->assertTrue($result->incremental);
        $this->assertSame(1, $result->created);
        $this->assertSame('token-2', $this->calendar->fresh()->sync_token);

        Http::assertSent(fn ($r) => $r->method() === 'REPORT' && str_contains((string) $r->body(), 'token-1'));
    }

    #[Test]
    public function an_incremental_sync_removes_events_the_server_reports_as_gone(): void
    {
        $this->calendar->update(['supports_sync_collection' => true, 'sync_token' => 'token-1']);

        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/gone.ics',
        ]);

        FakeICloud::fake(['sync-collection' => FakeICloud::multiStatus(
            [], syncToken: 'token-2', deleted: ['/12345678/calendars/home/gone.ics'],
        )]);

        $result = $this->sync();

        $this->assertSame(1, $result->deleted);
        $this->assertModelMissing($event);
    }

    #[Test]
    public function a_stale_sync_token_falls_back_to_a_full_pass(): void
    {
        $this->calendar->update(['supports_sync_collection' => true, 'sync_token' => 'expired']);

        FakeICloud::fake(
            ['calendar-query' => FakeICloud::multiStatus([
                '/12345678/calendars/home/swim.ics' => FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500'),
            ])],
            handler: function ($request) {
                // iCloud answers an unrecognised sync token with 403.
                if (str_contains((string) $request->body(), 'sync-collection')) {
                    return Http::response('<error>invalid token</error>', 403);
                }

                return FakeICloud::respond($request);
            },
        );

        $result = $this->sync();

        $this->assertFalse($result->incremental, 'A rejected token must trigger a full resync.');
        $this->assertSame(1, Event::count());
    }

    #[Test]
    public function a_full_sync_removes_events_that_vanished_inside_the_window(): void
    {
        $inWindow = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/vanished.ics',
            'start_at' => CarbonImmutable::now()->addDays(5),
            'end_at' => CarbonImmutable::now()->addDays(5)->addHour(),
        ]);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([])]);

        $result = $this->sync();

        $this->assertSame(1, $result->deleted);
        $this->assertModelMissing($inWindow);
    }

    #[Test]
    public function a_full_sync_never_deletes_events_outside_the_queried_window(): void
    {
        // Two years out is far beyond window_days_forward, so the server was
        // never asked about it. Deleting it would be data loss, not a sync.
        $outsideWindow = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/far-future.ics',
            'start_at' => CarbonImmutable::now()->addYears(2),
            'end_at' => CarbonImmutable::now()->addYears(2)->addHour(),
        ]);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([])]);

        $this->sync();

        $this->assertModelExists($outsideWindow);
    }

    #[Test]
    public function a_full_sync_never_deletes_an_event_still_waiting_to_be_pushed(): void
    {
        $pending = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/pending.ics',
            'needs_push' => true,
            'start_at' => CarbonImmutable::now()->addDay(),
            'end_at' => CarbonImmutable::now()->addDay()->addHour(),
        ]);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([])]);

        $this->sync();

        $this->assertModelExists($pending);
    }

    #[Test]
    public function a_reverted_occurrence_override_is_removed(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::recurringWithException('swim-1'),
        ])]);

        $this->sync();
        $this->assertSame(2, Event::count());

        // The override is deleted in iCloud, so the resource now holds only the
        // master. The stale override row must go with it.
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::event('swim-1', 'Swimming lesson', '20260707T163000', '20260707T171500', 'RRULE:FREQ=WEEKLY;BYDAY=TU'),
        ])]);

        $this->sync();

        $this->assertSame(1, Event::count());
        $this->assertNull(Event::first()->recurrence_id);
    }

    #[Test]
    public function force_discards_the_token_and_does_a_full_pass(): void
    {
        $this->calendar->update(['supports_sync_collection' => true, 'sync_token' => 'token-1']);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([])]);

        $result = $this->sync(force: true);

        $this->assertFalse($result->incremental);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'sync-collection'));
    }

    #[Test]
    public function two_calendars_may_hold_the_same_uid_without_colliding(): void
    {
        $other = Calendar::factory()->create([
            'calendar_account_id' => $this->account->id,
            'external_id' => '/12345678/calendars/other/',
        ]);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::event('shared-uid', 'Swimming', '20260707T163000', '20260707T171500'),
        ])]);

        $this->sync();

        app(CalDavManager::class)->sync($this->account)->sync($other);

        // Identity is scoped to the calendar, so an event invited to two
        // calendars is two rows, not one that flip-flops.
        $this->assertSame(2, Event::where('external_id', 'shared-uid')->count());
    }
}
