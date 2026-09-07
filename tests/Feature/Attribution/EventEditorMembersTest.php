<?php

namespace Tests\Feature\Attribution;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Attribution\EventAttributor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class EventEditorMembersTest extends TestCase
{
    use RefreshDatabase;

    protected Calendar $calendar;

    protected CalendarAccount $account;

    protected Member $simon;

    protected Member $jenna;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-01 09:00:00');

        $household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $household->id]));

        $this->account = CalendarAccount::factory()->create(['household_id' => $household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $this->account->id,
            'external_id' => FakeICloud::CALENDAR,
        ]);

        $this->simon = Member::factory()->create(['household_id' => $household->id, 'name' => 'Simon']);
        $this->simon->aliases()->create(['alias' => 'SW']);
        $this->jenna = Member::factory()->create(['household_id' => $household->id, 'name' => 'Jenna']);
        $this->jenna->aliases()->create(['alias' => 'JW']);

        FakeICloud::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_editor_shows_who_an_event_was_matched_to(): void
    {
        $event = Event::factory()->create(['calendar_id' => $this->calendar->id, 'title' => 'SW dentist']);
        app(EventAttributor::class)->apply($event);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->assertSet('memberIds', [(string) $this->simon->id])
            ->assertSet('isManual', false);
    }

    #[Test]
    public function changing_the_members_pins_them_against_later_syncs(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'SW dentist',
            'href' => '/12345678/calendars/home/a.ics',
        ]);
        app(EventAttributor::class)->apply($event);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('memberIds', [(string) $this->jenna->id])
            ->call('save')
            ->assertHasNoErrors();

        $event->refresh();
        $this->assertSame('manual', $event->attribution);
        $this->assertSame([$this->jenna->id], $event->members->pluck('id')->all());
    }

    #[Test]
    public function saving_without_touching_the_members_leaves_it_automatic(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'SW dentist',
            'href' => '/12345678/calendars/home/a.ics',
        ]);
        app(EventAttributor::class)->apply($event);

        // Only the location changed; attribution should keep improving on its own.
        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('location', 'High Street')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('auto', $event->fresh()->attribution);
    }

    #[Test]
    public function an_event_can_be_handed_back_to_automatic_matching(): void
    {
        $event = Event::factory()->create(['calendar_id' => $this->calendar->id, 'title' => 'SW dentist']);
        app(EventAttributor::class)->setManually($event, [$this->jenna->id]);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->assertSet('isManual', true)
            ->call('useAutomaticMembers')
            ->assertSet('isManual', false)
            ->assertSet('memberIds', [(string) $this->simon->id]);

        $this->assertSame('auto', $event->fresh()->attribution);
    }

    #[Test]
    public function a_new_event_is_attributed_from_its_title(): void
    {
        Livewire::test('phone.event-editor')
            ->call('edit')
            ->set('title', 'JW yoga')
            ->set('calendarId', $this->calendar->id)
            ->set('date', '2026-07-08')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->jenna->id], Event::firstOrFail()->members->pluck('id')->all());
    }

    #[Test]
    public function an_event_can_be_assigned_to_several_people_by_hand(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Dentist',
            'href' => '/12345678/calendars/home/a.ics',
        ]);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('memberIds', [(string) $this->simon->id, (string) $this->jenna->id])
            ->call('save');

        $this->assertEqualsCanonicalizing(
            [$this->simon->id, $this->jenna->id],
            $event->fresh()->members->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_manual_assignment_can_be_emptied_to_make_it_a_household_event(): void
    {
        $event = Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'SW bins',
            'href' => '/12345678/calendars/home/a.ics',
        ]);
        app(EventAttributor::class)->apply($event);

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->set('memberIds', [])
            ->call('save');

        $event->refresh();
        $this->assertSame('manual', $event->attribution);
        $this->assertCount(0, $event->members);
    }
}
