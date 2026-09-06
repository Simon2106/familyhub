<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDisplayToken;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WallDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 09:00:00');

        $this->household = Household::factory()->create(['name' => 'Test Household']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function calendarFor(?Member $member = null, bool $visible = true): Calendar
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        return Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'member_id' => $member?->id,
            'is_visible' => $visible,
        ]);
    }

    #[Test]
    public function it_shows_todays_events_grouped_under_each_member(): void
    {
        $ada = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ada']);
        $bea = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Bea']);

        Event::factory()->create([
            'calendar_id' => $this->calendarFor($ada)->id,
            'title' => 'Ada dentist',
            'start_at' => Carbon::today()->setTime(10, 0),
            'end_at' => Carbon::today()->setTime(11, 0),
        ]);

        Livewire::test('display.wall')
            ->assertSee('Ada')
            ->assertSee('Bea')
            ->assertSee('Ada dentist')
            // Bea has nothing today, so her column must say so rather than vanish.
            ->assertSee('Nothing on');
    }

    #[Test]
    public function a_multi_day_event_appears_on_every_day_it_touches(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id]);

        Event::factory()->create([
            'calendar_id' => $this->calendarFor($member)->id,
            'title' => 'Camping trip',
            'start_at' => Carbon::today()->setTime(17, 0),
            'end_at' => Carbon::today()->addDays(2)->setTime(11, 0),
        ]);

        $days = Livewire::test('display.wall')->instance()->days();

        $touched = collect($days)
            ->filter(fn (array $day) => $day['events_by_member']->flatten()->contains(
                fn (Event $e) => $e->title === 'Camping trip'
            ))
            ->pluck('date')
            ->values()
            ->all();

        $this->assertSame([
            Carbon::today()->toDateString(),
            Carbon::today()->addDay()->toDateString(),
            Carbon::today()->addDays(2)->toDateString(),
        ], $touched);
    }

    #[Test]
    public function it_hides_events_from_hidden_calendars_and_cancelled_events(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id]);

        Event::factory()->create([
            'calendar_id' => $this->calendarFor($member, visible: false)->id,
            'title' => 'Hidden calendar event',
        ]);

        Event::factory()->cancelled()->create([
            'calendar_id' => $this->calendarFor($member)->id,
            'title' => 'Cancelled event',
        ]);

        Livewire::test('display.wall')
            ->assertDontSee('Hidden calendar event')
            ->assertDontSee('Cancelled event');
    }

    #[Test]
    public function it_renders_the_whole_fortnight_so_day_switching_needs_no_round_trip(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id]);
        $calendar = $this->calendarFor($member);

        Event::factory()->create([
            'calendar_id' => $calendar->id,
            'title' => 'Far future thing',
            'start_at' => Carbon::today()->addDays(10)->setTime(9, 0),
            'end_at' => Carbon::today()->addDays(10)->setTime(10, 0),
        ]);

        // Present in the initial HTML, not fetched when the day is tapped.
        Livewire::test('display.wall')->assertSee('Far future thing');
    }

    #[Test]
    public function loading_the_display_does_not_issue_a_query_per_event(): void
    {
        $members = Member::factory()->count(3)->create(['household_id' => $this->household->id]);

        foreach ($members as $member) {
            $calendar = $this->calendarFor($member);

            Event::factory()->count(10)->create(['calendar_id' => $calendar->id]);
        }

        DB::enableQueryLog();
        Livewire::test('display.wall');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Household, members, events, calendars, members-for-calendars, checklists,
        // checklist items. A per-event or per-calendar lookup would blow past this.
        $this->assertLessThan(
            15,
            $queries,
            "Expected a handful of queries for 30 events, got {$queries} — likely an N+1.",
        );
    }

    #[Test]
    public function the_display_page_renders_end_to_end(): void
    {
        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ada']);

        $this->withCookie(EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertOk()
            ->assertSee('Test Household')
            ->assertSee('Ada')
            ->assertSee('Coming up');
    }
}
