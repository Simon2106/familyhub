<?php

namespace Tests\Feature\Ical;

use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Assistant\AssistantTools;
use App\Services\Ical\FeedSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Somebody else's calendar, read into this one.
 *
 * The point of the design is that nothing downstream learns there is a second
 * kind of event, so most of these tests are about the events table looking
 * exactly as it would if iCloud had produced them.
 */
class FeedSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected const URL = 'https://school.example/fixtures.ics';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function feeds(): FeedSubscription
    {
        return app(FeedSubscription::class);
    }

    /** A feed with one timed fixture and one all-day entry. */
    protected function ics(array $events = []): string
    {
        $events = $events ?: [
            "BEGIN:VEVENT\nUID:match-1\nSUMMARY:U11 v Hartley\nLOCATION:Rec ground\n"
                ."DTSTART:20260919T143000Z\nDTEND:20260919T160000Z\nEND:VEVENT",
            "BEGIN:VEVENT\nUID:tour-1\nSUMMARY:Tour weekend\nDTSTART;VALUE=DATE:20261003\n"
                ."DTEND;VALUE=DATE:20261005\nEND:VEVENT",
        ];

        return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Test//EN\n".implode("\n", $events)."\nEND:VCALENDAR\n";
    }

    /** What the feed currently answers with. Read by reference — see fake(). */
    protected string $body = '';

    protected int $status = 200;

    /**
     * One stub, answering from a variable.
     *
     * Http::fake() merges rather than replaces and the first matching stub
     * wins, so a second fake() for the same URL is silently ignored. The way
     * to make a feed change its mind is to change what the one stub reads.
     */
    protected function fake(?string $body = null, int $status = 200): void
    {
        $this->body = $body ?? $this->ics();
        $this->status = $status;

        Http::fake([self::URL => function () {
            return Http::response($this->body, $this->status);
        }]);
    }

    protected function nowAnswers(string $body, int $status = 200): void
    {
        $this->body = $body;
        $this->status = $status;
    }

    #[Test]
    public function subscribing_reads_the_feed_onto_an_ordinary_calendar(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures', '#0ea5e9');

        $this->assertSame(CalendarAccount::PROVIDER_ICS, $account->provider);
        $this->assertSame('School fixtures', $account->label);

        $calendar = $account->calendars->first();

        $this->assertSame('#0ea5e9', $calendar->colour);
        $this->assertTrue($calendar->is_visible);

        // The one thing that makes it read-only, in the one place anything asks.
        $this->assertFalse((bool) $calendar->is_writable);

        $this->assertSame(2, Event::where('calendar_id', $calendar->id)->count());
    }

    #[Test]
    public function a_timed_fixture_keeps_its_time_and_an_all_day_entry_stays_all_day(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');
        $calendar = $account->calendars->first();

        $match = Event::where('calendar_id', $calendar->id)->where('external_id', 'match-1')->first();

        $this->assertFalse((bool) $match->all_day);
        $this->assertSame('2026-09-19 14:30:00', $match->start_at->utc()->toDateTimeString());
        $this->assertSame('Rec ground', $match->location);

        $tour = Event::where('calendar_id', $calendar->id)->where('external_id', 'tour-1')->first();

        $this->assertTrue((bool) $tour->all_day);
        // DTEND is exclusive for a DATE value: a 3rd-to-5th tour ends on the 4th.
        $this->assertSame('2026-10-04', $tour->end_at->toDateString());
    }

    /** A fixture called off disappears from the feed, and must leave the wall. */
    #[Test]
    public function something_dropped_from_the_feed_is_taken_off_the_calendar(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $this->nowAnswers($this->ics([
            "BEGIN:VEVENT\nUID:tour-1\nSUMMARY:Tour weekend\nDTSTART;VALUE=DATE:20261003\n"
                ."DTEND;VALUE=DATE:20261005\nEND:VEVENT",
        ]));

        $this->feeds()->refresh($account);

        $this->assertSame(['tour-1'], Event::pluck('external_id')->all());
    }

    #[Test]
    public function a_changed_fixture_is_updated_rather_than_duplicated(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $this->nowAnswers($this->ics([
            "BEGIN:VEVENT\nUID:match-1\nSUMMARY:U11 v Hartley (moved)\nDTSTART:20260919T160000Z\n"
                ."DTEND:20260919T173000Z\nEND:VEVENT",
        ]));

        $this->feeds()->refresh($account);

        $this->assertSame(1, Event::count());
        $this->assertSame('U11 v Hartley (moved)', Event::first()->title);
        $this->assertSame('2026-09-19 16:00:00', Event::first()->start_at->utc()->toDateTimeString());
    }

    /**
     * A feed that is down this morning is not a reason to empty a calendar.
     */
    #[Test]
    public function a_feed_that_fails_leaves_what_was_already_read_alone(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $this->nowAnswers('nope', 503);

        $this->feeds()->refresh($account);

        $this->assertSame(2, Event::count());
        $this->assertSame('error', $account->fresh()->status);
        $this->assertStringContainsString('503', $account->fresh()->last_error);
    }

    #[Test]
    public function an_address_that_is_not_a_calendar_never_becomes_a_subscription(): void
    {
        Http::fake([self::URL => Http::response('<html><body>Please sign in</body></html>')]);

        Livewire::test('admin.calendars')
            ->call('startSubscribing')
            ->set('feedUrl', self::URL)
            ->set('feedName', 'School fixtures')
            ->call('subscribe')
            ->assertSee('did not return a calendar');

        $this->assertSame(0, CalendarAccount::where('provider', CalendarAccount::PROVIDER_ICS)->count());
    }

    #[Test]
    public function it_can_be_pointed_at_a_member_and_recoloured_from_admin(): void
    {
        $this->fake();

        $joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey']);

        Livewire::test('admin.calendars')
            ->call('startSubscribing')
            ->set('feedUrl', self::URL)
            ->set('feedName', 'School fixtures')
            ->set('feedColour', '#e11d48')
            ->set('feedMember', (string) $joey->id)
            ->set('feedRefresh', 1440)
            ->call('subscribe')
            ->assertSee('School fixtures')
            ->assertSee('Once a day');

        $account = CalendarAccount::where('provider', CalendarAccount::PROVIDER_ICS)->first();
        $calendar = $account->calendars->first();

        $this->assertSame($joey->id, $calendar->member_id);
        $this->assertSame('#e11d48', $calendar->colour);
        $this->assertSame(1440, $account->refreshMinutes());
    }

    #[Test]
    public function editing_a_subscription_keeps_the_events_it_already_read(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');
        $ids = Event::pluck('id')->all();

        Livewire::test('admin.calendars')
            ->call('editFeed', $account->id)
            ->assertSet('feedUrl', self::URL)
            ->set('feedName', 'Fixtures')
            ->call('subscribe');

        $this->assertSame(1, CalendarAccount::where('provider', CalendarAccount::PROVIDER_ICS)->count());
        $this->assertSame($ids, Event::pluck('id')->all());
        $this->assertSame('Fixtures', $account->fresh()->label);
    }

    #[Test]
    public function unsubscribing_takes_the_events_with_it(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures');

        Livewire::test('admin.calendars')->call('unsubscribe', $account->id);

        $this->assertSame(0, CalendarAccount::where('provider', CalendarAccount::PROVIDER_ICS)->count());
        $this->assertSame(0, Event::count());
    }

    /* ------------------------------ the schedule ---------------------- */

    #[Test]
    public function a_feed_is_only_re_read_when_its_own_interval_says_so(): void
    {
        $this->fake();

        $account = $this->feeds()->save($this->household, self::URL, 'School fixtures', refreshMinutes: 360);

        $this->assertFalse($account->fresh()->isDueForRefresh());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(7));

        $this->assertTrue($account->fresh()->isDueForRefresh());
    }

    #[Test]
    public function the_command_skips_a_feed_that_is_not_due(): void
    {
        $this->fake();

        $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $this->nowAnswers('should not be asked for', 500);

        $this->artisan('familyhub:sync-feeds')->assertSuccessful();

        // Untouched: the interval said no, so no request was made at all.
        $this->assertSame('ok', CalendarAccount::where('provider', 'ics')->first()->status);
    }

    #[Test]
    public function the_ordinary_icloud_sync_never_touches_a_subscription(): void
    {
        $this->fake();

        $this->feeds()->save($this->household, self::URL, 'School fixtures');

        // No credentials on an ics account, so this would blow up if it tried.
        $this->artisan('sync:calendars')->assertSuccessful();

        $this->assertSame(2, Event::count());
    }

    /**
     * The design claim, tested where it matters.
     *
     * Nothing downstream was taught about subscriptions, so if a fixture does
     * not show on the wall alongside the iCloud events, the whole approach was
     * the wrong one.
     */
    #[Test]
    public function a_subscribed_fixture_shows_on_the_wall_like_any_other_event(): void
    {
        $this->fake();

        CarbonImmutable::setTestNow('2026-09-19 09:00:00');

        $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $this->withoutVite()
            ->get(route('display', ['token' => config('familyhub.display.token')]))
            ->assertOk()
            ->assertSee('U11 v Hartley');
    }

    #[Test]
    public function the_assistant_can_read_a_subscribed_calendar(): void
    {
        $this->fake();

        $this->feeds()->save($this->household, self::URL, 'School fixtures');

        $said = app(AssistantTools::class)->run('calendar', [
            'from' => '2026-09-19', 'to' => '2026-09-19',
        ], $this->household);

        $this->assertStringContainsString('U11 v Hartley', $said);
        $this->assertStringContainsString('School fixtures', $said);
    }
}
