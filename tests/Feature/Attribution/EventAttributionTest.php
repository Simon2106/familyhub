<?php

namespace Tests\Feature\Attribution;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Services\Attribution\EventAttributor;
use App\Services\CalDav\CalDavManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class EventAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected CalendarAccount $account;

    protected Calendar $calendar;

    protected Member $simon;

    protected Member $jenna;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-01 09:00:00');

        $this->household = Household::factory()->create();
        $this->account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $this->account->id,
            'external_id' => FakeICloud::CALENDAR,
        ]);

        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $this->simon->aliases()->create(['alias' => 'SW']);

        $this->jenna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna']);
        $this->jenna->aliases()->create(['alias' => 'JW']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function event(string $title, ?string $location = null): Event
    {
        return Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'location' => $location,
        ]);
    }

    protected function attribute(Event $event): Event
    {
        app(EventAttributor::class)->forget();
        app(EventAttributor::class)->apply($event);

        return $event->fresh();
    }

    #[Test]
    public function an_event_naming_two_people_belongs_to_both(): void
    {
        $event = $this->attribute($this->event('SW + JW dentist'));

        $this->assertEqualsCanonicalizing(
            [$this->simon->id, $this->jenna->id],
            $event->members->pluck('id')->all(),
        );
    }

    #[Test]
    public function an_unrecognised_event_falls_back_to_the_calendar_owner(): void
    {
        $this->calendar->update(['member_id' => $this->simon->id]);

        $event = $this->attribute($this->event('Bin day'));

        $this->assertSame([$this->simon->id], $event->members->pluck('id')->all());
        $this->assertSame('calendar', $event->members->first()->pivot->reason);
    }

    #[Test]
    public function an_unrecognised_event_on_an_unowned_calendar_belongs_to_the_household(): void
    {
        $this->calendar->update(['member_id' => null]);

        $event = $this->attribute($this->event('Bin day'));

        // Nobody in particular — it is a household event.
        $this->assertCount(0, $event->members);
    }

    #[Test]
    public function a_recognised_name_beats_the_calendar_owner(): void
    {
        $this->calendar->update(['member_id' => $this->simon->id]);

        $event = $this->attribute($this->event('JW yoga'));

        $this->assertSame([$this->jenna->id], $event->members->pluck('id')->all());
    }

    #[Test]
    public function re_attributing_replaces_the_previous_guess(): void
    {
        $event = $this->attribute($this->event('SW dentist'));
        $this->assertSame([$this->simon->id], $event->members->pluck('id')->all());

        $event->update(['title' => 'JW dentist']);
        $event = $this->attribute($event);

        $this->assertSame([$this->jenna->id], $event->members->pluck('id')->all());
    }

    #[Test]
    public function a_manual_assignment_is_not_overwritten_by_attribution(): void
    {
        $event = $this->event('SW dentist');

        app(EventAttributor::class)->setManually($event, [$this->jenna->id]);

        // The title still says SW, but a person said otherwise.
        $this->attribute($event);

        $this->assertSame([$this->jenna->id], $event->fresh()->members->pluck('id')->all());
        $this->assertSame('manual', $event->fresh()->attribution);
    }

    #[Test]
    public function a_manual_assignment_survives_a_resync(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/dentist.ics' => FakeICloud::event(
                'dentist-1', 'SW dentist', '20260707T090000', '20260707T093000'
            ),
        ])]);

        // First sync attributes it to Simon by his alias.
        app(CalDavManager::class)->sync($this->account)->sync($this->calendar);
        $event = Event::firstOrFail();
        $this->assertSame([$this->simon->id], $event->members->pluck('id')->all());

        // A person corrects it to Jenna.
        app(EventAttributor::class)->setManually($event, [$this->jenna->id]);

        // iCloud sends the event again, unchanged.
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/dentist.ics' => FakeICloud::event(
                'dentist-1', 'SW dentist', '20260707T090000', '20260707T093000'
            ),
        ])]);
        app(CalDavManager::class)->sync($this->account)->sync($this->calendar->fresh(), force: true);

        $this->assertSame(
            [$this->jenna->id],
            Event::firstOrFail()->members->pluck('id')->all(),
            'A hand-picked assignment must survive later syncs.',
        );
    }

    #[Test]
    public function attribution_runs_during_a_sync(): void
    {
        $this->place('Sandy Gate', 'school', ['SG'], [$this->jenna->id => true]);

        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/a.ics' => FakeICloud::event('a', 'SW haircut', '20260707T090000', '20260707T093000'),
            '/12345678/calendars/home/b.ics' => FakeICloud::event('b', 'SG inset day', '20260708T090000', '20260708T093000'),
        ])]);

        app(CalDavManager::class)->sync($this->account)->sync($this->calendar);

        $this->assertSame([$this->simon->id], Event::where('title', 'SW haircut')->first()->members->pluck('id')->all());
        $this->assertSame([$this->jenna->id], Event::where('title', 'SG inset day')->first()->members->pluck('id')->all());
    }

    #[Test]
    public function attribution_runs_when_an_event_is_written_back(): void
    {
        FakeICloud::fake();

        $event = app(CalDavManager::class)->writer($this->account)->create($this->calendar, [
            'title' => 'JW yoga',
            'start_at' => CarbonImmutable::parse('2026-07-08 19:00'),
            'end_at' => CarbonImmutable::parse('2026-07-08 20:00'),
        ]);

        $this->assertSame([$this->jenna->id], $event->fresh()->members->pluck('id')->all());
    }

    #[Test]
    public function re_running_over_the_household_picks_up_a_new_alias(): void
    {
        $this->calendar->update(['member_id' => null]);
        $event = $this->attribute($this->event('Sienna swimming'));

        $this->assertCount(0, $event->members);

        // A member is added later whose name is already in old titles.
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Sienna']);

        $changed = app(EventAttributor::class)->applyToHousehold($this->household->fresh());

        $this->assertSame(1, $changed);
        $this->assertSame([$sienna->id], $event->fresh()->members->pluck('id')->all());
    }

    #[Test]
    public function re_running_over_the_household_leaves_manual_events_alone(): void
    {
        $event = $this->event('SW dentist');
        app(EventAttributor::class)->setManually($event, [$this->jenna->id]);

        app(EventAttributor::class)->applyToHousehold($this->household->fresh());

        $this->assertSame([$this->jenna->id], $event->fresh()->members->pluck('id')->all());
    }

    #[Test]
    public function an_event_can_be_handed_back_to_automatic_attribution(): void
    {
        $event = $this->event('SW dentist');
        app(EventAttributor::class)->setManually($event, [$this->jenna->id]);

        app(EventAttributor::class)->resetToAutomatic($event);

        $this->assertSame('auto', $event->fresh()->attribution);
        $this->assertSame([$this->simon->id], $event->fresh()->members->pluck('id')->all());
    }

    /** @param array<int, bool> $members */
    protected function place(string $name, string $type, array $aliases, array $members): Place
    {
        $place = Place::factory()->create([
            'household_id' => $this->household->id,
            'name' => $name,
            'type' => $type,
        ]);

        foreach ($aliases as $alias) {
            $place->aliases()->create(['alias' => $alias]);
        }

        foreach ($members as $memberId => $auto) {
            $place->members()->attach($memberId, ['include_automatically' => $auto]);
        }

        return $place;
    }
}
