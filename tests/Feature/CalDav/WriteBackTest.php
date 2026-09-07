<?php

namespace Tests\Feature\CalDav;

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Services\CalDav\CalDavManager;
use App\Services\CalDav\EventMapper;
use App\Services\CalDav\EventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class WriteBackTest extends TestCase
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

        FakeICloud::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function writer(): EventWriter
    {
        return app(CalDavManager::class)->writer($this->account);
    }

    #[Test]
    public function creating_an_event_puts_it_to_icloud(): void
    {
        $event = $this->writer()->create($this->calendar, [
            'title' => 'Parents evening',
            'start_at' => CarbonImmutable::parse('2026-07-08 18:00', 'Europe/London'),
            'end_at' => CarbonImmutable::parse('2026-07-08 19:00', 'Europe/London'),
            'location' => 'School hall',
        ]);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains((string) $r->body(), 'SUMMARY:Parents evening')
            && str_contains((string) $r->body(), 'LOCATION:School hall'));

        $this->assertNotNull($event->href);
        $this->assertSame('"etag-written"', $event->fresh()->etag);
        $this->assertFalse($event->fresh()->needs_push);
    }

    #[Test]
    public function a_create_refuses_to_overwrite_an_existing_resource(): void
    {
        $this->writer()->create($this->calendar, [
            'title' => 'New',
            'start_at' => CarbonImmutable::parse('2026-07-08 18:00'),
            'end_at' => CarbonImmutable::parse('2026-07-08 19:00'),
        ]);

        // If-None-Match:* is what stops a UID collision silently replacing
        // somebody else's event.
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->hasHeader('If-None-Match', '*'));
    }

    #[Test]
    public function an_update_sends_the_etag_we_last_saw(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
            'etag' => '"etag-original"',
        ]);

        $this->writer()->update($event, ['title' => 'Renamed']);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->hasHeader('If-Match', '"etag-original"'));
    }

    #[Test]
    public function a_concurrent_change_on_icloud_is_refused_not_clobbered(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
            'etag' => '"stale"',
        ]);

        FakeICloud::fake(handler: fn ($request) => $request->method() === 'PUT'
            ? Http::response('', 412)
            : FakeICloud::respond($request));

        $this->expectException(CalDavException::class);
        $this->expectExceptionMessage('changed on iCloud');

        $this->writer()->update($event, ['title' => 'Mine']);
    }

    #[Test]
    public function a_failed_push_leaves_the_event_flagged_for_retry(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
        ]);

        FakeICloud::fake(handler: fn ($request) => $request->method() === 'PUT'
            ? Http::response('boom', 500)
            : FakeICloud::respond($request));

        try {
            $this->writer()->update($event, ['title' => 'Will not reach iCloud']);
        } catch (CalDavException) {
            // expected
        }

        // The typed change survives locally and is marked as owing a push,
        // rather than being lost because the network blipped.
        $event->refresh();
        $this->assertTrue($event->needs_push);
        $this->assertSame('Will not reach iCloud', $event->title);
    }

    #[Test]
    public function deleting_an_event_removes_it_both_sides(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
            'etag' => '"etag-1"',
        ]);

        $this->writer()->delete($event);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->hasHeader('If-Match', '"etag-1"'));
        $this->assertModelMissing($event);
    }

    #[Test]
    public function deleting_something_already_gone_upstream_still_cleans_up_locally(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
        ]);

        FakeICloud::fake(handler: fn ($request) => $request->method() === 'DELETE'
            ? Http::response('', 404)
            : FakeICloud::respond($request));

        $this->writer()->delete($event);

        $this->assertModelMissing($event);
    }

    #[Test]
    public function a_read_only_calendar_refuses_writes(): void
    {
        $readOnly = Calendar::factory()->readOnly()->create([
            'calendar_account_id' => $this->account->id,
        ]);

        $this->expectException(CalDavException::class);
        $this->expectExceptionMessage('read-only');

        $this->writer()->create($readOnly, [
            'title' => 'Nope',
            'start_at' => CarbonImmutable::parse('2026-07-08 18:00'),
            'end_at' => CarbonImmutable::parse('2026-07-08 19:00'),
        ]);
    }

    #[Test]
    public function an_event_we_write_survives_a_round_trip_through_ics(): void
    {
        $event = $this->writer()->create($this->calendar, [
            'title' => 'Book club',
            'start_at' => CarbonImmutable::parse('2026-07-08 19:30', 'Europe/London'),
            'end_at' => CarbonImmutable::parse('2026-07-08 21:30', 'Europe/London'),
            'location' => 'The Crown',
            'notes' => 'Bring the book',
        ]);

        $ics = app(EventMapper::class)->toIcs($event);
        $parsed = app(EventMapper::class)->fromIcs($ics)[0];

        $this->assertSame('Book club', $parsed['title']);
        $this->assertSame('The Crown', $parsed['location']);
        $this->assertSame('Bring the book', $parsed['notes']);
        // 19:30 London in July is 18:30 UTC, and must still be after the trip.
        $this->assertSame('18:30', $parsed['start_at']->format('H:i'));
        $this->assertSame('20:30', $parsed['end_at']->format('H:i'));
    }

    #[Test]
    public function an_all_day_event_round_trips_without_gaining_a_day(): void
    {
        $event = $this->writer()->create($this->calendar, [
            'title' => 'Inset day',
            'all_day' => true,
            'start_at' => CarbonImmutable::parse('2026-07-09 00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-07-09 23:59', 'UTC'),
        ]);

        $parsed = app(EventMapper::class)->fromIcs(app(EventMapper::class)->toIcs($event))[0];

        // We emit the exclusive DTEND iCal requires, and must read it back as
        // the same single day rather than two.
        $this->assertTrue($parsed['all_day']);
        $this->assertSame('2026-07-09', $parsed['start_at']->toDateString());
        $this->assertSame('2026-07-09', $parsed['end_at']->toDateString());
    }
}
