<?php

namespace Tests\Feature\Display;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Photo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the screensaver shows behind the drifting clock.
 *
 * Everyone's day rather than one person's, and everything left rather than
 * the next thing — "one event" makes a five o'clock pick-up invisible the
 * moment a four o'clock one exists.
 */
class ScreensaverAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected Member $sienna;

    protected Member $joey;

    /** Midday, so there is a morning behind and an afternoon ahead. */
    protected const NOON = '2026-09-11 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOON);

        $this->household = Household::factory()->create(['timezone' => 'UTC']);
        $this->style('today');

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'is_visible' => true,
        ]);

        $this->sienna = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Sienna Wills', 'colour' => '#16a34a',
        ]);
        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey Wills', 'colour' => '#ea580c',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function event(string $title, string $startsAt, array $members = [], array $attributes = []): Event
    {
        $event = Event::factory()->create($attributes + [
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => $startsAt,
            'end_at' => CarbonImmutable::parse($startsAt)->addHour(),
        ]);

        if ($members !== []) {
            $event->members()->attach(collect($members)->pluck('id'));
        }

        return $event;
    }

    /**
     * Change the style, and make the change visible to the component.
     *
     * Household::current() memoises with once(), which is per-request in
     * production and therefore per-test here — so a style set after anything
     * has already asked for the household would otherwise be invisible.
     */
    protected function style(string $style): void
    {
        $this->household->setScreensaverStyle($style);

        Once::flush();
    }

    protected function agenda(): array
    {
        return Livewire::test('display.wall', ['token' => 'x'])->instance()->saverAgenda;
    }

    /* ------------------------------- today ------------------------------ */

    #[Test]
    public function it_shows_everything_left_today_not_only_the_next_thing(): void
    {
        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);
        $this->event('Football', '2026-09-11 17:30:00', [$this->joey]);
        $this->event('Book club', '2026-09-11 19:30:00');

        $agenda = $this->agenda();

        $this->assertNull($agenda['label'], 'Today needs no heading.');
        $this->assertSame(
            ['Swimming', 'Football', 'Book club'],
            array_column($agenda['events'], 'title'),
        );
    }

    /** A screensaver announcing this morning's dentist at nine at night. */
    #[Test]
    public function this_mornings_events_are_gone_by_the_afternoon(): void
    {
        $this->event('Dentist', '2026-09-11 09:00:00', [$this->sienna]);
        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);

        $this->assertSame(['Swimming'], array_column($this->agenda()['events'], 'title'));
    }

    /** An all-day thing is on all day, including the part that is left. */
    #[Test]
    public function an_all_day_event_stays_up_and_leads(): void
    {
        $this->event('Inset day', '2026-09-11 00:00:00', [$this->joey], [
            'all_day' => true,
            'end_at' => '2026-09-11 23:59:59',
        ]);
        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);

        $events = $this->agenda()['events'];

        $this->assertSame(['Inset day', 'Swimming'], array_column($events, 'title'));
        $this->assertSame('All day', $events[0]['when']);
    }

    #[Test]
    public function each_line_carries_the_members_colour_and_initials(): void
    {
        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);

        $line = $this->agenda()['events'][0];

        $this->assertSame('16:00', $line['when']);
        $this->assertSame([['initials' => 'SW', 'colour' => '#16a34a']], $line['who']);
    }

    #[Test]
    public function an_event_for_nobody_in_particular_belongs_to_everyone(): void
    {
        $this->event('Book club', '2026-09-11 19:30:00');

        $this->assertSame([], $this->agenda()['events'][0]['who']);
    }

    /** Four sets of initials is a line nobody reads. */
    #[Test]
    public function more_than_two_people_are_counted_rather_than_listed(): void
    {
        $third = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ada Wills']);

        $this->event('Outing', '2026-09-11 14:00:00', [$this->sienna, $this->joey, $third]);

        $line = $this->agenda()['events'][0];

        $this->assertCount(2, $line['who']);
        $this->assertSame(1, $line['extra_people']);
    }

    /* ------------------------------ overflow ---------------------------- */

    #[Test]
    public function it_shows_six_and_counts_the_rest(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->event('Thing '.$i, CarbonImmutable::parse('2026-09-11 13:00:00')->addMinutes($i * 20)->toDateTimeString());
        }

        $agenda = $this->agenda();

        $this->assertCount(6, $agenda['events']);
        $this->assertSame(3, $agenda['more']);
        $this->assertSame('Thing 0', $agenda['events'][0]['title'], 'Soonest first.');
    }

    /* ----------------------------- tomorrow ----------------------------- */

    /** An empty panel at nine in the evening tells nobody anything. */
    #[Test]
    public function once_today_is_done_it_falls_forward_to_tomorrow(): void
    {
        $this->event('Dentist', '2026-09-11 09:00:00', [$this->sienna]);
        $this->event('School run', '2026-09-12 08:30:00', [$this->joey]);
        $this->event('Swimming', '2026-09-12 16:00:00', [$this->sienna]);

        $agenda = $this->agenda();

        $this->assertSame('Tomorrow', $agenda['label']);
        $this->assertSame(['School run', 'Swimming'], array_column($agenda['events'], 'title'));
    }

    #[Test]
    public function tomorrow_is_a_look_ahead_and_shows_fewer(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->event('Thing '.$i, CarbonImmutable::parse('2026-09-12 08:00:00')->addHours($i)->toDateTimeString());
        }

        $agenda = $this->agenda();

        $this->assertSame('Tomorrow', $agenda['label']);
        $this->assertCount(4, $agenda['events']);
        $this->assertSame(3, $agenda['more']);
    }

    #[Test]
    public function a_wholly_empty_two_days_says_so(): void
    {
        $agenda = $this->agenda();

        $this->assertSame('Tomorrow', $agenda['label']);
        $this->assertSame([], $agenda['events']);
        $this->assertSame(0, $agenda['more']);
    }

    /* ------------------------------ the page ---------------------------- */

    #[Test]
    public function the_wall_draws_the_lines(): void
    {
        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);
        $this->event('Football', '2026-09-11 17:30:00', [$this->joey]);

        Livewire::test('display.wall', ['token' => 'x'])
            ->assertSee('Swimming')
            ->assertSee('Football')
            ->assertSee('16:00')
            ->assertSee('SW')
            ->assertSee('JW')
            ->assertSee('#16a34a', false);
    }

    /** A repeating event belongs on the screensaver every week, not once. */
    #[Test]
    public function a_repeating_event_shows_on_the_day_it_happens(): void
    {
        CarbonImmutable::setTestNow('2026-09-18 12:00:00');

        $this->event('Football training', '2026-09-11 17:00:00', [$this->joey], [
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);

        $this->assertSame(['Football training'], array_column($this->agenda()['events'], 'title'));
    }

    /* --------------------- photographs, with the day ------------------- */

    /**
     * The two styles were separate and the family wanted both at once: the
     * photographs are the thing worth looking at, and the day is the thing
     * worth knowing.
     */
    #[Test]
    public function the_photo_style_carries_the_clock_the_day_and_the_weather(): void
    {
        $this->style('photos');

        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);
        $this->event('Football', '2026-09-11 17:30:00', [$this->joey]);

        Livewire::test('display.wall', ['token' => 'x'])
            ->assertSee('Swimming')
            ->assertSee('Football')
            ->assertSee('16:00')
            ->assertSee('SW')
            // The clock and the date are drawn by Alpine from the browser's
            // own time, so what is asserted here is that the frame is there.
            ->assertSee('toLocaleDateString', false);
    }

    #[Test]
    public function nothing_drifts_over_a_photograph(): void
    {
        $this->style('photos');

        // Against the raw HTML: assertSee strips tags, so an attribute can
        // never match through it — and the bare word appears in the Alpine
        // that moves the clock, which is on the page whatever the style.
        $this->assertStringNotContainsString(
            'x-ref="driftingClock"',
            Livewire::test('display.wall', ['token' => 'x'])->html(),
        );

        $this->style('today');

        $this->assertStringContainsString(
            'x-ref="driftingClock"',
            Livewire::test('display.wall', ['token' => 'x'])->html(),
        );
    }

    /** The plain clock stays an option, and stays plain. */
    #[Test]
    public function the_plain_clock_style_is_a_clock_and_nothing_else(): void
    {
        $this->style('clock');

        $this->event('Swimming', '2026-09-11 16:00:00', [$this->sienna]);

        // The event is on the wall itself, of course — what matters is that
        // the screensaver's own panel is not drawn at all.
        $html = Livewire::test('display.wall', ['token' => 'x'])->html();

        $this->assertStringContainsString('x-ref="driftingClock"', $html);
        $this->assertStringNotContainsString('Nothing on tomorrow either.', $html);
    }

    #[Test]
    public function photographs_are_the_default_where_there_are_any(): void
    {
        // Its own empty photo directory: this asks what a household with no
        // photographs is given, and the real one has some in it.
        Storage::fake('public');

        // Never chosen: the household gets whatever suits what it has.
        $this->household->forceFill(['settings' => []])->save();

        Once::flush();

        $this->assertSame('clock', $this->household->fresh()->screensaverStyle());

        Photo::create([
            'household_id' => $this->household->id,
            'source' => 'folder',
            'disk' => 'public',
            'path' => 'photos/one.jpg',
        ]);

        $this->assertSame('photos', $this->household->fresh()->screensaverStyle());
    }
}
