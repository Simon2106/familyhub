<?php

namespace Tests\Feature\CalDav;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class EventEditorTest extends TestCase
{
    use RefreshDatabase;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-01 09:00:00');

        $household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $household->id]));

        $account = CalendarAccount::factory()->create(['household_id' => $household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'external_id' => FakeICloud::CALENDAR,
            'name' => 'Family',
        ]);

        FakeICloud::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_new_event_is_written_to_icloud(): void
    {
        Livewire::test('phone.event-editor')
            ->call('edit')
            ->set('title', 'Parents evening')
            ->set('calendarId', $this->calendar->id)
            ->set('date', '2026-07-08')
            ->set('startTime', '18:00')
            ->set('endTime', '19:00')
            ->set('location', 'School hall')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains((string) $r->body(), 'SUMMARY:Parents evening'));

        $event = Event::firstOrFail();
        $this->assertSame('Parents evening', $event->title);
        // 18:00 London in July is 17:00 UTC.
        $this->assertSame('17:00', $event->start_at->format('H:i'));
    }

    #[Test]
    public function editing_an_event_loads_it_in_household_time(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Swimming',
            'start_at' => CarbonImmutable::parse('2026-07-07 15:30', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-07-07 16:15', 'UTC'),
        ]);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            // Stored as 15:30 UTC, but the form must show the 16:30 the family sees.
            ->assertSet('startTime', '16:30')
            ->assertSet('endTime', '17:15')
            ->assertSet('date', '2026-07-07')
            ->assertSet('title', 'Swimming');
    }

    #[Test]
    public function an_edit_is_pushed_with_the_etag(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
            'etag' => '"etag-1"',
        ]);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('title', 'Swimming lesson')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->hasHeader('If-Match', '"etag-1"'));
        $this->assertSame('Swimming lesson', $event->fresh()->title);
    }

    #[Test]
    public function deleting_from_the_editor_removes_it_from_icloud(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
        ]);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->call('deleteEvent');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertModelMissing($event);
    }

    #[Test]
    public function an_end_before_the_start_is_treated_as_running_past_midnight(): void
    {
        Livewire::test('phone.event-editor')
            ->call('edit')
            ->set('title', 'Party')
            ->set('calendarId', $this->calendar->id)
            ->set('date', '2026-07-08')
            ->set('startTime', '21:00')
            ->set('endTime', '01:00')
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::firstOrFail();

        $this->assertSame('2026-07-08', $event->start_at->timezone('Europe/London')->toDateString());
        $this->assertSame('2026-07-09', $event->end_at->timezone('Europe/London')->toDateString());
    }

    #[Test]
    public function an_all_day_event_covers_the_whole_day(): void
    {
        Livewire::test('phone.event-editor')
            ->call('edit')
            ->set('title', 'Inset day')
            ->set('calendarId', $this->calendar->id)
            ->set('date', '2026-07-09')
            ->set('allDay', true)
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::firstOrFail();

        $this->assertTrue($event->all_day);
        $this->assertSame('2026-07-09', $event->start_at->timezone('Europe/London')->toDateString());
        $this->assertSame('2026-07-09', $event->end_at->timezone('Europe/London')->toDateString());
    }

    #[Test]
    public function a_conflict_is_shown_rather_than_overwriting(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'href' => '/12345678/calendars/home/swim.ics',
            'etag' => '"stale"',
        ]);

        FakeICloud::fake(handler: fn ($request) => $request->method() === 'PUT'
            ? Http::response('', 412)
            : FakeICloud::respond($request));

        $component = Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('title', 'Mine')
            ->call('save');

        $this->assertStringContainsString('changed on iCloud', $component->get('error'));
        // The sheet stays open so the edit is not lost.
        $component->assertSet('open', true);
    }

    #[Test]
    public function it_validates_before_touching_icloud(): void
    {
        Livewire::test('phone.event-editor')
            ->call('edit')
            ->set('title', '')
            ->set('calendarId', $this->calendar->id)
            ->call('save')
            ->assertHasErrors('title');

        Http::assertNothingSent();
    }

    #[Test]
    public function demo_calendars_are_not_offered_as_write_targets(): void
    {
        $demoAccount = CalendarAccount::factory()->create([
            'household_id' => Household::current()->id,
            'provider' => 'demo',
        ]);
        $demoCalendar = Calendar::factory()->create(['calendar_account_id' => $demoAccount->id]);

        $writable = Livewire::test('phone.event-editor')->instance()->writableCalendars();

        // Writing to a demo calendar would attempt a CalDAV PUT with no credentials.
        $this->assertTrue($writable->contains('id', $this->calendar->id));
        $this->assertFalse($writable->contains('id', $demoCalendar->id));
    }

    #[Test]
    public function a_read_only_calendar_is_not_offered(): void
    {
        $readOnly = Calendar::factory()->readOnly()->create([
            'calendar_account_id' => $this->calendar->calendar_account_id,
        ]);

        $writable = Livewire::test('phone.event-editor')->instance()->writableCalendars();

        $this->assertFalse($writable->contains('id', $readOnly->id));
    }

    #[Test]
    public function another_households_event_cannot_be_edited(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAccount = CalendarAccount::factory()->create(['household_id' => $otherHousehold->id]);
        $otherCalendar = Calendar::factory()->create(['calendar_account_id' => $otherAccount->id]);
        $otherEvent = Event::factory()->create(['calendar_id' => $otherCalendar->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('phone.event-editor')->call('edit', $otherEvent->id);
    }
}
